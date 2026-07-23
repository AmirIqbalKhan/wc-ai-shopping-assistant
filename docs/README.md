# WooCommerce AI Shopping Assistant

A WordPress/WooCommerce plugin that adds a conversational, natural-language product finder to any store. Shoppers describe what they want in plain English ("something for a rainy hike under $80") and get grounded, real-time product recommendations pulled directly from the store's live catalog.

## Why this exists

Traditional WooCommerce filtering (categories, tags, price sliders) only works when a shopper already knows what they're looking for. A large share of purchase intent is fuzzy or multi-constraint ("a gift for my mom who likes gardening," "a lightweight laptop bag that fits a 14-inch MacBook"). Keyword search and filters don't handle that well, and shoppers who can't find what they want either give up or scroll indefinitely. This plugin closes that gap with a chat-based assistant that reasons over the actual product catalog instead of matching keywords.

## Core features

- **Conversational product search** — natural-language queries, multi-turn refinement ("actually, show me it in blue")
- **Live catalog sync** — indexes new products, edits, and stock changes automatically via WooCommerce hooks, no manual re-sync
- **Grounded recommendations only** — the assistant is restricted to real, in-stock (or explicitly marked) products; it cannot invent products that don't exist
- **Explainable matches** — each suggested product includes a one-line reason it was chosen
- **Store-owner insights dashboard** — surfaces what shoppers are asking for but *not* finding, useful for spotting inventory or content gaps
- **Configurable widget placement** — storefront-wide chat bubble or embedded on category/search pages

## How it works (high level)

1. On activation, the plugin indexes the existing product catalog (title, description, price, category, tags, attributes, stock) and generates a semantic embedding for each product.
2. WooCommerce hooks (`woocommerce_new_product`, `woocommerce_update_product`, stock-status hooks, deletion hooks) keep that index current in near real time, processed in the background via Action Scheduler so the admin UI never lags.
3. When a shopper sends a query, the plugin retrieves the most relevant candidate products via semantic search, then passes only those candidates to the AI model along with the shopper's request.
4. The model returns a ranked shortlist with short justifications, rendered as product cards linking to the real product pages.

See `ARCHITECTURE.md` for the full technical design and `AGENT_DESIGN.md` for how the conversational agent itself is built.

## Requirements

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 8.1+
- Action Scheduler (bundled with WooCommerce, no separate install needed)
- An API key for the chosen AI provider (configured in plugin settings)
- MySQL 5.7+ / MariaDB 10.3+ (for custom embedding storage table)

## Installation (MVP / development)

1. Clone or copy the plugin into `wp-content/plugins/wc-ai-shopping-assistant`
2. Activate the plugin from the WordPress admin Plugins screen
3. Go to **WooCommerce → AI Assistant → Settings** and add your AI provider API key
4. Trigger the initial catalog index from the settings screen (**Reindex Catalog**), or let it run automatically on first activation
5. Add the assistant widget via the provided Gutenberg block, shortcode `[wc_ai_assistant]`, or enable the site-wide floating widget in settings

## Project structure (proposed)

```
wc-ai-shopping-assistant/
├── wc-ai-shopping-assistant.php     # Plugin bootstrap
├── includes/
│   ├── class-indexer.php            # Catalog indexing + embedding generation
│   ├── class-hooks.php              # WooCommerce hook listeners
│   ├── class-agent.php              # Conversational agent / query handling
│   ├── class-retrieval.php          # Semantic search over indexed products
│   ├── class-admin-dashboard.php    # Insights dashboard
│   └── class-settings.php           # Plugin settings page
├── assets/
│   ├── js/widget.js                 # Frontend chat widget
│   └── css/widget.css
├── blocks/
│   └── ai-assistant-block/          # Gutenberg block registration
└── docs/
    ├── README.md
    ├── ARCHITECTURE.md
    ├── AGENT_DESIGN.md
    └── ROADMAP.md
```

## Documentation

- [`ARCHITECTURE.md`](./ARCHITECTURE.md) — system architecture, database schema, sync pipeline, scaling notes
- [`AGENT_DESIGN.md`](./AGENT_DESIGN.md) — conversational agent design, prompt strategy, grounding and anti-hallucination measures
- [`ROADMAP.md`](./ROADMAP.md) — phased MVP plan, feature backlog, known technical challenges

## License

TBD — recommend GPLv2 or later for WordPress.org directory compatibility, or a commercial license if distributing outside the directory.
