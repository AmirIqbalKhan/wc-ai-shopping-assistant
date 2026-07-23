# Architecture

## 1. System overview

The plugin has four main subsystems:

1. **Indexer** — builds and maintains a searchable representation of the product catalog
2. **Hook listeners** — keep the index in sync with live WooCommerce data
3. **Retrieval layer** — finds the most relevant products for a given shopper query
4. **Agent layer** — turns retrieved products + shopper query into a grounded, conversational response (see `AGENT_DESIGN.md`)

```
┌─────────────┐     ┌──────────────┐     ┌───────────────┐     ┌─────────────┐
│  WooCommerce │────▶│   Hooks      │────▶│  Action        │────▶│  Indexer    │
│  product CRUD│     │   Listener   │     │  Scheduler     │     │  (embed +   │
└─────────────┘     └──────────────┘     │  (background)  │     │  store)     │
                                          └───────────────┘     └──────┬──────┘
                                                                        │
                                                                        ▼
                                                              ┌───────────────────┐
                                                              │ wp_ai_product_index│
                                                              │ (custom table)     │
                                                              └─────────┬─────────┘
                                                                        │
Shopper types query ──▶ Chat widget ──▶ REST endpoint ──▶ Retrieval layer ──▶ Agent ──▶ Response
                                                                        ▲
                                                                        │
                                                              (semantic search against
                                                               wp_ai_product_index)
```

## 2. Data model

WooCommerce products are just WordPress posts of type `product`, with:
- Core fields on `wp_posts` (title, description/content, excerpt/short description, status)
- Price, SKU, stock status/quantity as post meta (`wp_postmeta`)
- Categories/tags/attributes as taxonomies (`wp_terms`, `wp_term_relationships`)
- Variable products have child `product_variation` posts

Rather than querying this relational structure live on every chat message (slow, and not embedding-search-friendly), the plugin maintains a **denormalized, embedding-augmented index** in a custom table.

### Custom table: `wp_ai_product_index`

| Column              | Type            | Notes                                                        |
|---------------------|-----------------|---------------------------------------------------------------|
| `id`                | BIGINT UNSIGNED | Primary key                                                    |
| `product_id`        | BIGINT UNSIGNED | FK to `wp_posts.ID`                                            |
| `variation_id`      | BIGINT UNSIGNED NULL | Set if this row represents a specific variation, else NULL |
| `title`             | TEXT            | Product title                                                   |
| `summary_text`      | TEXT            | Concatenated title + short description + key attributes, used as the embedding source text |
| `price`             | DECIMAL(10,2)   | Current price (sale price if active)                            |
| `stock_status`      | VARCHAR(20)     | `instock`, `outofstock`, `onbackorder`                          |
| `category_names`    | TEXT            | Denormalized, comma-separated, for quick filtering               |
| `attributes_json`   | JSON            | Color, size, material, etc.                                     |
| `embedding`         | BLOB / VECTOR   | Serialized embedding vector (see §5 for storage strategy)        |
| `last_indexed_at`   | DATETIME        | For staleness checks / debugging                                 |
| `product_url`       | TEXT            | Cached permalink                                                 |

Indexes: `product_id`, `stock_status`, and a composite index on `(stock_status, price)` to support pre-filtering before semantic ranking.

## 3. Keeping the index in sync

The plugin never does a full catalog re-scan on write operations — only on install/manual reindex. Day-to-day updates are handled by listening to WooCommerce's built-in action hooks and updating a single row at a time.

| Event                          | Hook                                          | Action                                      |
|---------------------------------|------------------------------------------------|----------------------------------------------|
| New product published            | `woocommerce_new_product`                      | Index the new product                          |
| Product edited (price, description, etc.) | `woocommerce_update_product`         | Re-embed and update the row                     |
| Stock level/status changed        | `woocommerce_product_set_stock`, `woocommerce_product_set_stock_status` | Update `stock_status`/quantity only (no re-embed needed) |
| Product deleted or unpublished    | `before_delete_post`, `wp_trash_post` (scoped to `product` post type) | Remove row from index |
| Variation added/changed           | `woocommerce_update_product_variation`         | Index/update the variation row                 |

All of the above are queued as background jobs via **Action Scheduler** (already bundled with WooCommerce) rather than run synchronously in the request that saves the product. This keeps the WordPress admin responsive — a store owner editing a product doesn't wait on an embedding API call before the "Product updated" screen loads.

```php
// Simplified example
add_action( 'woocommerce_update_product', function ( $product_id ) {
    as_enqueue_async_action( 'wcai_reindex_product', [ 'product_id' => $product_id ], 'wcai' );
});

add_action( 'wcai_reindex_product', function ( $product_id ) {
    WCAI_Indexer::index_single_product( $product_id );
});
```

## 4. Retrieval flow (per chat query)

1. Shopper submits a query via the widget → REST endpoint (`/wp-json/wcai/v1/query`)
2. Lightweight pre-filter (optional): stock status = in stock, price bounds if the query mentions a budget, category hints if mentioned
3. Query text is embedded using the same embedding model used for indexing
4. Cosine similarity (or provider-native vector search) ranks indexed products against the query embedding
5. Top N candidates (e.g., 15–30) are passed to the agent layer as structured context — never the full catalog
6. Agent returns a ranked, justified shortlist (see `AGENT_DESIGN.md`)
7. Response rendered as product cards in the widget, each linking to the real `product_url`

## 5. Storage & scaling strategy

Two tiers, selectable based on catalog size:

**Tier A — Small/medium catalogs (up to ~5,000 products)**
- Store embeddings as serialized vectors (BLOB or JSON array) directly in `wp_ai_product_index`
- Compute cosine similarity in PHP at query time, or via a lightweight PHP vector-math extension
- Simpler ops story: no external service, everything lives in the WordPress database

**Tier B — Large catalogs (tens of thousands+ of products)**
- Push embeddings to a dedicated vector database (e.g., Pinecone, Qdrant, or a self-hosted pgvector instance)
- `wp_ai_product_index` keeps the denormalized metadata (price, stock, url) but the vector itself lives externally, referenced by `product_id`
- Retrieval step queries the vector DB for top-N IDs, then joins back to the local table for current price/stock before responding — this also naturally protects against stale price/stock data if the vector DB lags

The plugin should default to Tier A and expose a settings toggle to switch to Tier B once a catalog size threshold is crossed, since Tier B requires additional configuration (API keys, endpoint).

## 6. Handling product variations

Two supported modes, configurable per store:

- **Parent-level indexing (default)** — index only the parent product; variation details (available sizes/colors) are included as an `attributes_json` summary. Simpler, sufficient for most queries.
- **Variation-level indexing** — index each variation as its own row with its own price/stock/attributes. Enables precise queries like "the blue one in size medium" but increases index size and indexing overhead. Recommended only for stores where variations differ significantly in price or availability.

## 7. Performance & cost controls

- Batch embedding calls where possible (e.g., initial full-catalog index) rather than one API call per product
- Cache identical/near-identical shopper queries for a short TTL to avoid redundant model calls on high-traffic stores
- Rate-limit the chat endpoint per session/IP to control API spend on the free tier
- Debounce rapid successive edits to the same product (e.g., bulk price updates) so it's re-indexed once, not once per field change

## 8. Security & data handling

- Only publicly visible product data is sent to the AI provider — never customer PII, order data, or draft/private products
- API keys stored using WordPress's standard options API with encryption at rest where the hosting environment supports it
- REST endpoint validates nonces for logged-in contexts and applies rate limiting for anonymous shoppers
- Aligns with the EU Cyber Resilience Act's vulnerability-disclosure expectations: maintain a documented process for reporting and patching security issues, given the September 2026 compliance deadline for WordPress plugin/theme authors
