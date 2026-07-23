# Roadmap

## Phase 1 — MVP (proof of concept)

Goal: prove the core loop works end-to-end on a single store with a modest catalog.

- [ ] Plugin scaffold, activation/deactivation hooks, settings page (API key entry)
- [ ] Full-catalog indexer (manual "Reindex" button, synchronous for small catalogs)
- [ ] Custom table `wp_ai_product_index` + embedding storage (Tier A only)
- [ ] Hook listeners for new/updated/deleted products and stock changes, queued via Action Scheduler
- [ ] Retrieval layer: embed query, cosine similarity search, top-N candidates
- [ ] Agent layer: single-model call, structured JSON response, grounding checks
- [ ] Basic frontend widget: chat bubble, product cards, single-turn queries only
- [ ] Manual QA on a test store with ~200 products

**Exit criteria**: a shopper can type a natural-language query and get accurate, in-stock, correctly priced product suggestions with working links.

## Phase 2 — Usability & trust

- [ ] Multi-turn conversation support (refinement, "not that one")
- [ ] Clarifying-question flow for ambiguous queries
- [ ] Live price/stock re-check at render time (staleness protection)
- [ ] "Explain this match" reasoning shown per product
- [ ] Widget placement options: floating bubble, embedded block, shortcode
- [ ] Gutenberg block for easy embedding on any page
- [ ] Rate limiting on the REST endpoint (anonymous + logged-in)
- [ ] Basic analytics: query volume, click-through rate on suggested products

## Phase 3 — Scale & store-owner value

- [ ] Tier B storage support (external vector DB) for large catalogs
- [ ] Variation-level indexing option
- [ ] Insights dashboard: unmatched/low-confidence queries, trending requests
- [ ] Bulk reindex performance improvements (batched embedding calls, progress UI)
- [ ] Multi-language support (queries and product data in non-English stores)
- [ ] A/B testing hooks so store owners can measure conversion lift

## Phase 4 — Monetization & polish

- [ ] Usage-tiered pricing (query volume caps per plan)
- [ ] White-label / agency mode (manage the assistant across multiple client stores)
- [ ] Voice input on mobile
- [ ] Public API/webhook so results can be surfaced outside the default widget (e.g., custom themes)

## Known technical challenges

| Challenge | Mitigation |
|---|---|
| API cost scaling with traffic | Caching, rate limiting, cheaper model for constraint extraction, generous but capped free tier |
| Messy/incomplete product data (missing attributes, poor descriptions) | Onboarding checklist nudging store owners to improve product data; fallback to title/category-only matching when attributes are sparse |
| Index staleness under high edit frequency | Debounced re-indexing, live re-check of price/stock at render time regardless of index freshness |
| Large catalogs slow to embed initially | Batched, queued indexing with visible progress bar rather than a blocking operation |
| Trust — shoppers doubting the assistant is "real" | Closed-set grounding, visible reasoning per product, live data re-check (see `AGENT_DESIGN.md` §4) |
| Admin performance impact | All indexing work run via Action Scheduler background jobs, never inline with admin save actions |

## Explicitly out of scope for MVP

- Checkout/purchase actions within the chat widget (recommend only, don't transact)
- Cross-store/marketplace search
- Image-based ("find something that looks like this photo") queries
