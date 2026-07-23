# Changelog

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
