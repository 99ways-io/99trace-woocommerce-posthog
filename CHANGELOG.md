# Changelog

All notable changes to 99Trace. Versions follow [semantic versioning](https://semver.org); the WordPress.org readme carries the same entries for users.

## 1.1.1 – 2026-11-23

Changes asked for by the WordPress.org plugin review (21 Nov 2026). Events, orders and settings behave as in 1.1.0.

### Changed
- Name "99Trace: Easy Integration with PostHog for WooCommerce"; plugin folder, main file, text domain and WordPress.org slug `99trace-integration-with-posthog-for-woocommerce`. Stored names (`w99_phwc_*` options, `_99w_ph_*` order data, `ph_wc_*` cookies) and hook names are unchanged.
- One prefix for everything the plugin declares, as WordPress.org asks: PHP namespace `W99Phwc` (was `W99\Trace`), constants `W99_PHWC_TRACE_VERSION`, `W99_PHWC_TRACE_FILE`, `W99_PHWC_TRACE_DIR` (were `W99_TRACE_*`), matching the `w99_phwc_` hooks and options. The `W99_TRACE_MODE` wp-config constant is unchanged.
- Request data is sanitized where it is read: the user agent and `Sec-Purpose` headers with `sanitize_text_field()`; cookies are read in one place (`Util::request_cookies()`), and the values the plugin uses are validated or sanitized after decoding (distinct and session IDs, the Meta click ID); the thank-you page's link request reads only its three fields; the Events settings form is read field by field after a second nonce and capability check.

## 1.1.0 – 2026-11-18

### Added
- Every product in an event now carries everything WooCommerce shows about it: its attributes by name (`attributes`, such as the bundle or colour bought), the variation's own address with its attributes (`variant_url`), the parent's name and SKU, all categories, tags and brands, the shipping class, and WooCommerce's product data (type, prices, sale, stock status, dimensions, tax, visibility, dates, ratings). This applies to `products[]` in purchases, refunds, failed payments, Checkout Started and Cart Viewed, and to Product Added, Product Removed and Product Viewed. Fields sent before keep their names and values. See `docs/properties.md`.
- Events → Product custom fields: send product custom fields (meta) too, the visible ones or all of them. Off by default, because some of them are internal (purchase cost, supplier codes). WooCommerce's own cost-of-goods fields count as internal and come only with "all". Custom fields, cost of goods, sales counts and stock numbers never go into data printed into pages.
- Filter `w99_phwc_product_properties`.

## 1.0.0 – 2026-11-17

First public release. 99Trace was piloted on live shops under the name **99Hog for WooCommerce** (0.2 to 0.6); this release renames it and prepares it for the WordPress.org plugin directory.

### Added
- Translation-ready: every admin string uses the `99trace-integration-with-posthog-for-woocommerce` text domain; `languages/99trace-integration-with-posthog-for-woocommerce.pot` holds the template.
- A suggested privacy policy text under Settings → Privacy → Policy Guide, written from the current settings (consent, browser module, IP location, customer details, PostHog region).
- `uninstall.php`: deleting the plugin removes its settings, temporary data and scheduled tasks. Order data (the identity saved at checkout and each event's delivery state) stays with the orders, so a reinstall never sends an order twice.
- While 99Hog for WooCommerce is still active, 99Trace waits and explains how to switch, so the two never send the same events.
- `W99_TRACE_MODE` in `wp-config.php` forces the mode (`off`, `test`, `on`); 99Hog's `W99_PHWC_MODE` is still read.
- Old 99Hog settings and status bookmarks redirect to the 99Trace tabs.

### Changed
- Name 99Trace; plugin folder, main file, text domain and WordPress.org slug `99trace-integration-with-posthog-for-woocommerce`; PHP namespace `W99\Trace`; constants `W99_TRACE_*`.
- Server events carry `$lib: 99trace` (was `99hog-woocommerce`) and the user agent `99trace/<version>`.
- `w99_phwc_posthog_js_config` can add posthog-js options but no longer replace the ones 99Trace depends on (`before_send`, `bootstrap`, persistence, consent and the others listed under Advanced), as the settings field already did.
- Unchanged on purpose: stored names (`w99_phwc_*` options, order meta, scheduled tasks, `ph_wc_*` cookies), the `99hog:v1:` event-ID scheme and the `99hog:order:` prefix of anonymous orders, so an order sent before the rename and retried or refunded after it keeps its IDs.

### Fixed
- The coverage report missed orders that a gateway marks paid without a payment date, such as cash on delivery. 99Trace sent their purchases; the report now counts them too.

## Pilot versions (as 99Hog for WooCommerce)

- **0.6.0**: shop staff, page editors and page-builder previews are not recorded by default; carts that a checkout page fills while loading are no longer sent as `Product Added`; events of pages the browser loads in advance wait until the page is shown.
- **0.5.1**: switching over from another PostHog plugin no longer re-sends the purchases it already reported.
- **0.5.0**: one visitor ID shared by the browser and the server; cart and checkout events sent at the end of the request; customer details on the person; three choices per browser feature.
- **0.4.0**: settings page with tabs, an events table (switch off or rename), consent modes before the visitor answers, and a person profile for every visitor.
- **0.3.0**: one PostHog identity across browser and server, the consent switch and the PostHog settings in the plugin.
- **0.2.x**: server-side order events with true UTC timestamps and the buyer's IP address for location.
