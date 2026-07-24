=== ShopAsk AI – Shopping Assistant for WooCommerce ===
Contributors: amiriqbalkhan
Tags: woocommerce, ai, search, shopping assistant, product finder
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conversational, natural-language product finder for WooCommerce stores. Grounded recommendations from your live catalog.

== Description ==

ShopAsk AI – Shopping Assistant for WooCommerce lets shoppers describe what they want in plain language and receive product recommendations from your **live catalog**, with match reasons and working product links.

This plugin is an independent product and is not affiliated with or endorsed by Automattic or WooCommerce.

**Shopper experience**

* Natural-language search and multi-turn chat
* Clarifying questions when intent is unclear
* Grounded answers — only products from your indexed catalog
* Live price and stock at render time
* Floating bubble, search bar, Ask AI button, and embedded panel
* Optional voice input (Web Speech API)
* Add to cart for simple in-stock products

**Store owner tools**

* Multi-provider AI: OpenAI, Claude, Gemini, LongCat, OpenRouter, or a custom OpenAI-compatible API
* Hybrid in-database retrieval (no external vector database required)
* Analytics and unmatched-demand insights
* White-label title, accent color, and branding toggle

This plugin sends data to an external AI provider **only after** you configure an API key and when a feature is used (reindex or shopper query). It does not phone home to the plugin author.

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**, or install from WordPress.org.
2. Activate the plugin (WooCommerce must be active).
3. Go to **WooCommerce → AI Assistant**.
4. Choose your AI provider, enter your API key, and save.
5. Click **Reindex Catalog** and wait until indexing finishes.
6. Visit the storefront — the floating assistant appears when enabled, or place shortcodes / the block.

== Frequently Asked Questions ==

= Does this plugin phone home? =

No. Outbound requests go only to the AI provider (or custom API base) you configure, and optionally to a webhook URL you set. Nothing is sent to the plugin author’s servers.

= What data is sent to the AI provider? =

Product titles, descriptions, and related catalog text may be sent when indexing/embeddings run. Shopper search prompts and short catalog snippets may be sent when the assistant answers a query. This happens only after you save an API key and use those features.

= Who pays for AI API usage? =

You do. Queries use your provider API key. The plugin includes rate limits and a daily query soft-cap (default 500/day) to help control spend.

= Can I hide branding? =

Yes. Enable white-label in **WooCommerce → AI Assistant → Settings**.

= Which shortcodes are available? =

`[wc_ai_assistant type="search"]`, `[wc_ai_assistant type="button"]`, `[wc_ai_assistant type="panel"]`, and `[wc_ai_assistant type="floating"]`.

== Screenshots ==

1. Storefront shopping assistant chat with a grounded product recommendation card.

== Changelog ==

= 0.3.8 =
* Trademark-safe rename: ShopAsk AI – Shopping Assistant for WooCommerce (slug shopask-ai-shopping-assistant)
* Plugin Check: block apiVersion 3, remove load_plugin_textdomain / languages .gitkeep, Tested up to 7.0
* Harden custom-table SQL with %i identifiers, table whitelist, and object-cache for hot reads

= 0.3.7 =
* Rename display title for WordPress.org Guideline 17 (superseded by 0.3.8 ShopAsk branding)

= 0.3.6 =
* WordPress.org readiness: readme.txt, External services disclosure, self-hosted Outfit fonts
* Harden public query endpoint (REST nonce + daily soft-cap); public search API key via header only
* Privacy policy content expanded; uninstall cleanup improved

= 0.3.5 =
* Fix floating chat close control centering against theme button styles

= 0.3.4 =
* Premium storefront UI and unified admin hub (Settings / Analytics / Insights)

= 0.3.3 =
* Hide Limits & plan and Integrations from settings UI

= 0.3.2 =
* Security hardening, durable rate limits, privacy hooks, HPOS declare

= 0.3.1 =
* Flexible storefront widgets (search, button, panel, floating)

= 0.3.0 =
* Multi-provider support and local embeddings fallback

= 0.2.0 =
* Sessions, analytics, hybrid retrieval, voice, public API

= 0.1.0 =
* Initial MVP

== Upgrade Notice ==

= 0.3.7 =
Display name updated for WordPress.org trademark guidelines. Plugin slug and data are unchanged.

= 0.3.6 =
Adds daily query soft-cap (default 500) and requires a REST nonce for browser chat queries. Self-hosts Outfit fonts (no Bunny CDN).

== External services ==

This plugin connects to external services that you configure. It does **not** send data until an API key (or webhook URL) is saved and a feature is used.

**AI chat and embeddings (merchant-configured provider)**

* Services (depending on settings): OpenAI (`api.openai.com`), Anthropic Claude (`api.anthropic.com`), Google Gemini (OpenAI-compatible Gemini endpoint), LongCat (`api.longcat.chat`), OpenRouter (`openrouter.ai`), or a custom OpenAI-compatible base URL you enter.
* Data sent: product titles, descriptions, and related catalog fields during indexing/embeddings; shopper prompts (up to 500 characters) and short catalog candidate snippets during chat.
* When: after you save an API key, and when you reindex the catalog or a shopper uses the assistant.
* Why: generate embeddings and grounded product recommendations.
* Provider terms / privacy (examples): [OpenAI](https://openai.com/policies/privacy-policy), [Anthropic](https://www.anthropic.com/legal/privacy), [Google](https://policies.google.com/privacy), [OpenRouter](https://openrouter.ai/privacy), LongCat / custom: see your provider’s documentation.

**Optional merchant webhook**

* Service: HTTPS URL you configure in settings.
* Data sent: public search request payload you choose to integrate with.
* When: when the public search API is used and a webhook URL is set.
* Why: notify your own systems of searches.

Fonts are bundled locally (Outfit, SIL Open Font License). No remote font CDN is used.

== Privacy ==

* Shopper search text and session tokens may be stored in your WordPress database for analytics for up to 90 days.
* Search text may be sent to your configured AI provider as described under External services.
* Use **Tools → Export Personal Data** / **Erase Personal Data** for users who have an associated assistant session token.
* Suggested privacy policy text is registered with WordPress via the Privacy Policy guide.
* Store owners should also review their AI provider’s privacy policy and disclose the assistant on their storefront privacy page.
