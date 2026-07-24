# Changelog

## 0.3.8

- Trademark-safe rename: **ShopAsk AI – Shopping Assistant for WooCommerce** (`shopask-ai-shopping-assistant` slug / text domain / main file)
- Plugin Check: `block.json` apiVersion 3, remove `load_plugin_textdomain` and `languages/.gitkeep`, Tested up to 7.0
- DB hardening: table whitelist helper, `$wpdb->prepare` `%i` identifiers, object-cache for usage / rate-limit / analytics / indexed-count reads

## 0.3.7

- Guideline 17 display-title pass (superseded by 0.3.8 ShopAsk branding)

## 0.3.6

- WordPress.org readiness: `readme.txt`, External services / Privacy disclosure, full GPLv2 LICENSE
- Self-host Outfit fonts (SIL OFL); remove Bunny Fonts CDN
- Harden `POST /wcai/v1/query` with REST nonce; daily query soft-cap (default 500); public search API key via header only
- Privacy policy text expanded; uninstall cleans user meta, debounce transients, Action Scheduler jobs
- `load_plugin_textdomain`, shortcode/block asset detection, a11y label + focus return

## 0.3.5

- Fix floating chat close control centering against theme button styles (hard reset + absolute icon placement)

## 0.3.4

- Premium storefront UI: Outfit typography, mobile chat sheet, suggestion chips, skeleton thinking, richer product cards with Add to cart
- Unified WooCommerce → AI Assistant hub with Settings / Analytics / Insights tabs, status strip, and polished admin flows

## 0.3.3

- Hide Limits & plan and Integrations from settings; usage caps disabled (unlimited) until re-enabled later

## 0.3.2

- Security & cost: durable rate-limit table, gated click tracking, daily caps, embedding metering, non-autoloaded secrets
- Catalog visibility / password gating; retrieval fallback without BLOB filesort; HPOS declare; uninstall + privacy hooks
- LongCat aligned with platform docs (base URL normalization, LongCat-2.0 default, max_tokens, Test connection)
- Hardened API-key save/reindex (Action Scheduler guards, mbstring-safe local embeddings)

## 0.3.1

- Flexible widgets: AI search bar, Ask AI button, embedded panel, floating bubble
- Shortcode `type` / `label` attributes and Gutenberg layout picker
- Settings for site-wide floating toggle and optional auto-insert search bar

## 0.3.0

- Multi-provider support: OpenAI, Claude, Gemini, LongCat, OpenRouter, Custom
- Provider-aware model dropdowns in admin settings
- Local embeddings fallback for providers without an embeddings API
- Multi-turn sessions, analytics, insights, hybrid retrieval, placements, voice, public API

## 0.2.0

- Phase 2–4 features: sessions, shortcode/block, analytics/insights, hybrid in-DB retrieval, usage plans, white-label, voice, webhook

## 0.1.0

- Initial MVP: indexer, retrieval, grounded agent, floating widget
