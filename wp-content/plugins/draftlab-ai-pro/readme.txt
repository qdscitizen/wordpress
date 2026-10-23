=== DraftLab AI Pro - Batch Generator & Product Creator for WooCommerce ===
Contributors: sunchongzhou2026
Tags: woocommerce, ai, pro, batch, generation, bulk, ecommerce
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.22
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro version — batch generate descriptions for multiple products, full product fields (inventory, shipping, attributes), custom AI prompt templates, Quick Create, generation history & diff, auto-image matching from Pexels. Supports DeepSeek & OpenAI.

== Description ==

Stop writing WooCommerce product descriptions manually — and stop creating products from scratch. **AI Product Generator for WooCommerce** does both:

1. **Generate descriptions** for existing products — select, configure, click. AI writes SEO-optimized descriptions in seconds.
2. **Create new products from keywords** (Quick Create) — type a product idea, and AI generates a complete product draft with title, description, pricing, SKU, and SEO meta.

Powered by DeepSeek V4 and OpenAI GPT models.

### Why use this plugin?

Writing product descriptions for every item in your WooCommerce store is time-consuming. This plugin automates the entire process — select your products, choose your settings, and let AI generate compelling descriptions that convert visitors into customers.

### Features

**Free Features:**
* Single product AI description generation
* Quick Create — generate complete product drafts from keywords
* Multiple AI providers: DeepSeek (V4 Flash, V4 Pro) and OpenAI (GPT-4o, GPT-4o-mini)
* Multi-language support (English, 中文, 日本語, and custom prompts)
* Custom tone options (Professional, Casual, Concise)
* Clean WordPress-native React admin panel
* SEO meta title and description generation
* Unlimited usage — no caps, no quotas

**Pro Features (via Freemius):**
* Bulk generation — generate descriptions for multiple products at once
* Full product fields — inventory, shipping, attributes
* Pexels auto-image matching for product photos
* Custom AI prompt templates
* One-click Apply All & generation history
* Priority support

### How it works

1. Select products from your WooCommerce product list
2. Choose generation strategy (title, description, SEO meta, or all)
3. Pick language and tone
4. Add optional keywords to guide the AI
5. Click generate — AI creates optimized descriptions
6. Review and apply with one click

### Perfect for

* WooCommerce store owners with large product catalogs
* Dropshippers who need to create unique product descriptions
* eCommerce agencies managing multiple WooCommerce stores
* Anyone who wants SEO-optimized product content without manual writing

== Installation ==

1. Upload the `wooai-desc` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → AI Descriptions → Settings
4. Configure your AI provider (OpenAI or DeepSeek) and API key
5. Start generating product descriptions

== Frequently Asked Questions ==

= Which AI providers are supported? =

OpenAI (GPT-4o, GPT-4o-mini) and DeepSeek (V4 Pro, V4 Flash).

= Does it work with any WooCommerce theme? =

Yes, the admin panel is built with WordPress React components and works with all themes.

= Is my API key secure? =

Yes, API keys are stored in WordPress options and never exposed to the frontend.

= Can I generate descriptions in multiple languages? =

Yes. English, 中文 (Chinese), 日本語 (Japanese), and custom language prompts are supported.

= Is there a free version? =

Yes. The free version is fully functional with unlimited single-product generation and Quick Create. Upgrade to Pro for batch generation, full product fields, and auto-image matching.

== External Services ==

This plugin connects to external services for AI content generation:

**DeepSeek API** (https://www.deepseek.com/)
- Used to generate AI product descriptions and create product drafts
- Sends product information (name, description, attributes) only when you trigger generation
- Privacy Policy: https://www.deepseek.com/privacy
- Terms of Service: https://www.deepseek.com/terms

**Pexels API** (https://www.pexels.com/api/)
- Used to auto-fetch product images (Pro feature)
- Sends search keywords only when you create a product with image matching enabled
- Privacy Policy: https://www.pexels.com/privacy
- Terms of Service: https://www.pexels.com/terms

You may configure your own API keys for both services in the plugin settings.

== Source Code ==

Development repository: https://gitee.com/sunchongzhou/wooai-desc
Build tools: npm/webpack. Run `npm run build` to compile the React admin panel.

== Screenshots ==

1. Products dashboard with bulk selection
2. AI generation with before/after comparison
3. Quick Create — generate product drafts from keywords
4. Settings panel with AI provider configuration

== Changelog ==

= 1.0.15 =
* All buttons, dropdowns, and inputs now have consistent 8px border-radius
* Security compliance verified (escaping, sanitization, nonce/CSRF)

= 1.0.14 =
* Pagination with page number buttons + Prev/Next
* Search bar with explicit Search/Reset buttons (wider, rounded)
* Logs error messages now include [#product_id] for easy search
* Unified 32px height + 8px border-radius on all form controls and buttons
* Quick Create, Save Settings, Test Connection buttons all rounded
* Improved UI consistency across Dashboard, Generator, Logs, and Settings pages

= 1.0.13 =
* Page number buttons in pagination (not just Prev/Next)
* Active page highlighted with WP blue #2271b1

= 1.0.12 =
* Quick Create 6-paragraph description structure
* Price/stock/sale backend fallbacks
* Freemius sandbox fix (is_sandbox + skip button hidden)
* Card hover scale + CSS Grid full-width layout
* Reports directory for BotWriter analysis
