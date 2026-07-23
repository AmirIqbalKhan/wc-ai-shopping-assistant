# WooCommerce AI Shopping Assistant

A WordPress / WooCommerce plugin that adds a conversational product finder to your store. Shoppers describe what they want in plain language — *“something for a rainy hike under $80”* — and get grounded recommendations from your **live catalog**, with explainable match reasons and working product links.

Built for store owners who want smarter discovery without inventing products, prices, or stock.

---

## Features

### Shopper experience
- **Natural-language search** — multi-constraint queries the filter UI cannot handle well
- **Multi-turn chat** — refine with follow-ups (*“show me it in blue”*, *“not that one”*)
- **Clarifying questions** when intent is ambiguous
- **Grounded answers only** — the model may only recommend IDs returned from your indexed catalog
- **Live price & stock** at render time (protects against stale index data)
- **Per-product reasons** explaining why each item matched
- **Floating bubble**, **inline AI search bar**, **Ask AI button**, and **embedded chat panel** — place via shortcode, Gutenberg block, or auto-insert near the header
- **Voice input** (Web Speech API) where the browser supports it

### Catalog intelligence
- Automatic indexing via WooCommerce hooks + Action Scheduler (background, non-blocking)
- **Hybrid retrieval** — SQL / FULLTEXT prefilter, then cosine ranking on a bounded candidate set (no external vector database required)
- **Parent** or **variation-level** indexing
- Reindex progress UI in admin

### AI providers
Choose your chat provider from settings:

| Provider | Notes |
|----------|--------|
| **OpenAI** | Chat + API embeddings |
| **Claude (Anthropic)** | Native Messages API; local embeddings |
| **Gemini (Google)** | OpenAI-compatible endpoint |
| **LongCat Chat** | OpenAI-compatible chat; local embeddings |
| **OpenRouter** | Route many models through one key |
| **Custom** | Any OpenAI-compatible base URL |

### Store-owner tools
- **Analytics** — query volume, product click-through rate, top queries (7 / 30 days)
- **Insights** — unmatched / low-confidence requests (demand gaps for merchandising)
- **Usage plans** — Free / Pro / Agency monthly query caps (config-only; no payment gateway)
- **White-label** — custom title, accent color, hide “Powered by”
- **Public search API** + optional **webhook** for themes and integrations
- Rate limits for anonymous visitors and logged-in users

---

## Requirements

- WordPress **6.4+**
- WooCommerce **8.0+**
- PHP **8.1+**
- Action Scheduler (bundled with WooCommerce)
- MySQL 5.7+ / MariaDB 10.3+
- An API key for your chosen AI provider

---

## Installation

### From ZIP (WordPress admin)

1. Download the latest release ZIP (or zip the plugin folder).
2. In WP Admin go to **Plugins → Add New → Upload Plugin**.
3. Upload `wc-ai-shopping-assistant.zip`, install, and activate.
4. Open **WooCommerce → AI Assistant**.
5. Select your **AI provider**, paste the **API key**, confirm model + base URL.
6. Click **Reindex Catalog** and wait for the progress bar to finish.
7. Visit the storefront — the chat bubble appears when floating mode is enabled.

### From Git (developers)

```bash
cd wp-content/plugins
git clone https://github.com/AmirIqbalKhan/wc-ai-shopping-assistant.git
```

Then activate in **Plugins** and follow the settings steps above.

---

## Quick configuration

**WooCommerce → AI Assistant**

1. **AI provider** — OpenAI, Claude, Gemini, LongCat, OpenRouter, or Custom  
2. **API key** — from your provider dashboard (leave blank on later saves to keep the existing key)  
3. **Chat model** — pick from the dropdown or type a custom model ID  
4. **Embeddings** — *Auto* (recommended): local for Claude/LongCat; API for OpenAI/Gemini/OpenRouter  
5. **Widgets** — enable floating bubble and/or auto-insert search bar (see below)  
6. Use **Test connection**, then **Reindex Catalog** after the first save or after large catalog changes  

### LongCat Chat

Use the [LongCat API Platform](https://longcat.chat/platform/docs/):

- Provider: **LongCat Chat**
- API base (stored for this plugin): `https://api.longcat.chat/openai/v1`  
  (Official SDK base is `https://api.longcat.chat/openai`; the plugin appends `/chat/completions`.)
- Default model: `LongCat-2.0`
- Auth: Bearer app key from the LongCat platform
- Embeddings: local (LongCat has no embeddings endpoint)

---

## Widgets & placements

Place the assistant wherever you want on your site — hero, header/nav area, homepage section, product pages, or a floating corner bubble. Search and button widgets open a chat overlay with live product recommendations.

### Widget types

| Type | Shortcode | Best for |
|------|-----------|----------|
| **Search bar** | `[wc_ai_assistant type="search"]` | Hero, header, any content section |
| **Button** | `[wc_ai_assistant type="button" label="Ask AI"]` | CTAs anywhere on the site |
| **Chat panel** | `[wc_ai_assistant type="panel"]` | Dedicated page or full-width section |
| **Floating** | `[wc_ai_assistant type="floating"]` | Page-local floating bubble |

Site-wide floating bubble can also be toggled in **WooCommerce → AI Assistant → Floating button** (no shortcode needed).

### Examples

**Hero / homepage search**

```
[wc_ai_assistant type="search"]
```

**Header or banner CTA**

```
[wc_ai_assistant type="button" label="Find products with AI"]
```

**Full embedded assistant on a “Help me shop” page**

```
[wc_ai_assistant type="panel"]
```

### Gutenberg block

1. Edit any page or post.  
2. Insert the **AI Shopping Assistant** block.  
3. In the sidebar, choose layout: **Search bar**, **Button**, **Chat panel**, or **Floating**.  
4. Optionally set a custom button/search label.

### Settings options

Under **WooCommerce → AI Assistant → Widget & placement**:

- **Floating button** — show or hide the site-wide AI bubble  
- **Auto-insert search bar** — off, or insert near the top of the page (after `body` opens / near header)  
- Or leave auto-insert off and place widgets yourself with shortcodes or the block  

Custom label example: `[wc_ai_assistant type="button" label="Ask our stylist"]`

---

## How it works

```
WooCommerce product CRUD
        │
        ▼
  Hook listeners ──► Action Scheduler ──► Indexer (embed + upsert)
                                              │
                                              ▼
                                   wp_ai_product_index (MySQL)

Shopper message
        │
        ▼
  REST /wcai/v1/query
        │
        ├─► Session (constraints, history, shown IDs)
        ├─► Hybrid retrieval (prefilter + cosine)
        ├─► Grounded agent (JSON + ID checks + live WC lookup)
        └─► Query log (+ optional webhook)
```

1. Products are denormalized into `{$wpdb->prefix}ai_product_index` with a summary text and embedding vector.
2. On each chat turn, the query is narrowed (stock, budget, FULLTEXT), then ranked with cosine similarity.
3. Only top candidates are sent to the LLM. The response is validated against that closed ID set.
4. Cards are enriched with **live** WooCommerce price, stock, image, and permalink before display.

---

## Admin screens

| Screen | Purpose |
|--------|---------|
| **WooCommerce → AI Assistant** | Provider, models, placement, plans, white-label, reindex |
| **WooCommerce → AI Analytics** | Queries, clicks, CTR, top queries, monthly plan usage |
| **WooCommerce → AI Insights** | Unmatched shopper intents / catalog gaps |

### Analytics events

- Every shopper query is logged (text + result count + match flag). Query text can contain personal details and is retained for **90 days**, then deleted. Privacy exporters/erasers are registered.
- Product card clicks call `POST /wp-json/wcai/v1/click` for CTR.
- Unmatched queries feed the Insights dashboard for inventory planning.

---

## REST API

### Chat (storefront)

`POST /wp-json/wcai/v1/query`

```json
{
  "query": "lightweight rain jacket under $80",
  "session_token": "optional-existing-token"
}
```

### Click tracking

`POST /wp-json/wcai/v1/click`

```json
{
  "product_id": 123,
  "query_id": 45,
  "session_token": "…"
}
```

### Public search (themes / apps)

`GET|POST /wp-json/wcai/v1/search`

Authenticate with a user who can `manage_woocommerce`, **or** send header:

```
X-WCAI-API-Key: <public_api_key from settings>
```

### Webhook

If a webhook URL is set, each successful chat query fires a non-blocking POST with an anonymized payload (`product_ids`, counts, variant — not full query text / PII).

---

## Developer hooks

```php
// A/B variant label attached to agent context / response.
add_filter( 'wcai_ab_variant', function ( $variant, $session ) {
	return 'experiment_b';
}, 10, 2 );

// After a successful shopper query.
add_action( 'wcai_query_completed', function ( $payload ) {
	// $payload: session_token, query_id, reply_text, products, …
} );
```

---

## Security & trust

- Catalog fields plus the shopper’s query (and short conversation history) are sent to the configured AI provider. Orders and customer accounts are not. Hidden / password-protected products are not indexed or recommended.
- Recommendations are **closed-set**: hallucinated product IDs are dropped server-side.
- Price/stock are re-checked from WooCommerce at render time.
- Anonymous and logged-in rate limits are configurable.
- Monthly plan caps soft-block excess traffic.

---

## Project structure

```
wc-ai-shopping-assistant/
├── wc-ai-shopping-assistant.php   # Bootstrap
├── includes/                      # PHP classes (indexer, agent, REST, analytics, …)
├── assets/                        # Icons, storefront widget + admin UI
│   ├── icon-128x128.png           # WordPress.org plugin icon
│   ├── icon-256x256.png           # Retina plugin icon
│   └── icon.svg                   # Vector master
├── .wordpress-org/                # Directory assets for wordpress.org SVN
├── blocks/ai-assistant-block/     # Gutenberg block
├── docs/                          # Architecture, agent design, roadmap
└── tests/smoke-check.php          # Offline structure checks
```

Deeper design notes live in [`docs/`](./docs/):

- [Architecture](./docs/ARCHITECTURE.md)
- [Agent design](./docs/AGENT_DESIGN.md)
- [Roadmap](./docs/ROADMAP.md)

---

## License

GPL-2.0-or-later (WordPress.org compatible).

---

## Author

**Aamir Iqbal Khan**  
Repository: [github.com/AmirIqbalKhan/wc-ai-shopping-assistant](https://github.com/AmirIqbalKhan/wc-ai-shopping-assistant)

Contributions via issues and pull requests are welcome.
