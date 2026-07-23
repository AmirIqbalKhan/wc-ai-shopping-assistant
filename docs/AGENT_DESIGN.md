# Agent Design

This document covers the design of the conversational layer — how a shopper's natural-language message becomes a grounded, trustworthy product recommendation.

## 1. Design principles

1. **Grounding over generation** — the agent must never invent a product, price, or stock status. It only ranks and describes what the retrieval layer actually returned.
2. **Retrieval-constrained context** — the model never sees the full catalog, only the top candidate set from the retrieval layer (see `ARCHITECTURE.md` §4). This keeps prompts small, cheap, and less prone to the model "wandering" toward irrelevant items.
3. **Explainability by default** — every recommendation includes a short, specific reason it matched, not just a bare list.
4. **Graceful uncertainty** — if no strong matches exist, the agent says so and offers a next step (browse a category, adjust budget) rather than forcing a weak match.

## 2. Conversation flow

```
Shopper message
      │
      ▼
Query understanding  ──▶ extract: intent, constraints (price, category, attributes), sentiment
      │
      ▼
Retrieval layer       ──▶ top-N candidate products (see ARCHITECTURE.md)
      │
      ▼
Agent reasoning step   ──▶ rank candidates, draft justification per product
      │
      ▼
Response formatting    ──▶ structured JSON → rendered product cards + short reply text
      │
      ▼
Conversation state update ──▶ store constraints for follow-up turns
```

## 3. Prompt architecture

The agent call is built from four components assembled per turn:

1. **System instructions** — fixed rules: only recommend from the provided candidate list, cite specific attributes that justify each match, never state a product is in stock/priced at X unless that's what's in the provided data, keep responses concise.
2. **Store context** — lightweight store-level info (currency, shipping notes if configured) so responses feel native to the store.
3. **Candidate product data** — the top-N retrieved products, passed as structured data (JSON), not prose, to reduce ambiguity:
   ```json
   [
     {
       "id": 4821,
       "title": "Trailhead Packable Rain Shell",
       "price": 68.00,
       "stock_status": "instock",
       "category": "Jackets",
       "attributes": { "waterproof": true, "weight_g": 210 }
     }
   ]
   ```
4. **Conversation history** — prior turns in this session, so follow-ups like "show me it in blue" resolve against what was already discussed.

## 4. Grounding / anti-hallucination measures

This is the most important part of the design, since a shopping assistant that invents products or misstates price/stock directly damages trust and revenue.

- **Closed-set constraint**: the system prompt explicitly instructs the model to select only from the provided candidate IDs. The rendering layer cross-checks every product ID in the model's response against the candidate list before displaying it — any ID not in that list is dropped rather than shown.
- **No free-text prices**: the model is instructed to reference price only via the structured data field, not to restate or calculate it in prose, reducing the chance of a misquoted number.
- **Stale-data protection**: at render time (not just retrieval time), the plugin does a final live lookup of price/stock for the recommended product IDs directly from WooCommerce, so even if the index lagged slightly behind a very recent change, the shopper never sees wrong information.
- **Fallback response**: if retrieval returns no candidates above a similarity threshold, the agent is instructed to say so plainly and suggest a broadened search, rather than stretching to recommend a weak match.

## 5. Conversation state management

Each chat session (tied to a browser session or logged-in user) tracks:

- Extracted constraints so far (budget ceiling, category, attributes like color/size mentioned)
- Previously shown product IDs (to avoid repeating results and to support "not that one, something else")
- Turn count (used to trigger a "would you like help narrowing this down?" nudge if the conversation is going long without a resolution)

State is stored server-side (transient or dedicated session table) keyed by a session token issued to the widget, not in the client alone, so it can't be tampered with and survives page navigation within the same visit.

## 6. Response format

The agent returns structured output (JSON) rather than freeform prose, which the frontend widget renders into UI:

```json
{
  "reply_text": "Here are a few waterproof options under $80:",
  "products": [
    {
      "id": 4821,
      "reason": "Waterproof shell, well under your $80 budget"
    },
    {
      "id": 5190,
      "reason": "Packable rain jacket, lightweight for hiking"
    }
  ],
  "clarifying_question": null
}
```

If the query is ambiguous (e.g., "something for hiking" with no other constraints), `clarifying_question` can be populated instead of forcing a guess — e.g., "Are you looking for footwear, apparel, or gear?"

## 7. Model & cost considerations

- Use a smaller/cheaper model for query understanding and constraint extraction (a lightweight, fast task), and reserve a stronger model for the final ranking/justification step if cost is a concern — or use a single capable model for both if simplicity is preferred for the MVP.
- Cache constraint-extraction results within a session so re-parsing isn't repeated unnecessarily on every follow-up turn.
- Log (anonymized) queries that returned no good matches — this doubles as the data source for the store-owner insights dashboard described in `README.md`.

## 8. Admin-facing insight extraction

Every session's final unresolved or low-confidence query is logged (query text may include personal details shoppers typed; retained 90 days) to a lightweight table for the insights dashboard. This lets store owners see patterns like "42 shoppers asked for X in the last 30 days but we don't carry it" — a natural upsell for inventory planning, independent of the core assistant feature.
