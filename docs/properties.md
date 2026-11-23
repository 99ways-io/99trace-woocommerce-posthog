# Event properties: where each comes from

Parity target: the properties that a widely used third-party PostHog plugin for WooCommerce ("the previous plugin" below) and posthog-js put on events, so that dashboards built on them keep working after a switch, plus what the server reliably knows that the browser does not.

Rule: a property is sent only when its source is known. Names stay those of the previous plugin where it has them, so existing insights keep working; differences are listed at the end.

## `Order Completed` (server)

| Property | Source | Notes |
| --- | --- | --- |
| `order_id`, `order_number` | WooCommerce order | `order_id` as before (string) |
| `affiliation` | constant `WooCommerce` | as before |
| `total`, `subtotal`, `shipping`, `tax`, `discount`, `coupon`, `currency` | WooCommerce order | as before; numbers, major units |
| `revenue` | order total (amount charged) | **as before** (the previous plugin sends the total); kept so revenue charts and experiment metrics stay comparable |
| `net_revenue` | subtotal − discounts | new: merchandise after discounts, excl. tax and shipping |
| `products[]` | order lines | as before: `product_id` (parent), `variant` (variation ID), `sku`, `category`, `name`, `price`, `quantity`, `position`, `url`, `image_url`; new `variant_name`, `line_total`. `price` is the unit price paid before cart discounts (the previous plugin sent the product's catalogue price at that moment). Since 1.1.0 each line also carries the product's public WooCommerce data: see [product properties](#product-properties-110) |
| `products_string` | order lines | as before (`|SKU|SKU|`) |
| `quantity` | order lines | new: units |
| `payment_method`, `payment_method_title` | order | new |
| `created_via`, `customer_type` | order | new (`checkout`; `guest` / `registered`) |
| `order_placed_at` | order creation, UTC | new; the event timestamp is the payment time |
| `returning_customer` | WooCommerce: earlier paid order with the same account or billing e-mail | new; the e-mail itself is never sent |
| `wc_attribution_*` (`source_type`, `referrer`, `utm_source/medium/campaign/content/term`, `session_entry`, `session_pages`, `session_count`, `device_type`) | WooCommerce order attribution (first-party, recorded at checkout) | new; WooCommerce's own last-touch session rules, kept apart from PostHog's. URLs stripped like all others; left out for orders counted without consent |
| `$session_entry_url`, `$session_entry_referrer`, `$session_entry_referring_domain`, `$session_entry_pathname`, `$session_entry_host`, `$session_entry_utm_*`, `$session_entry_gclid`, `$session_entry_gad_source`, `$session_entry_gbraid`, `$session_entry_wbraid`, `$session_entry_fbclid`, `$session_entry__kx` | posthog-js at checkout (snapshot) | same names and meaning as on browser events: the PostHog session's entry. Absent on cookie-only orders (no snapshot): `$session_id` ties them to the session |
| `utm_*`, `gclid`, `fbclid`, … | posthog-js at checkout (checkout URL's own parameters) | as on browser events |
| `$fbc` | posthog-js (Meta click ID it keeps in its cookie, `{value, delivered}` in 1.447) | when present |
| `$browser`, `$browser_version`, `$os`, `$os_version`, `$device_type`, `$device`, `$raw_user_agent`, `$screen_*`, `$viewport_*`, `$browser_language`, `$browser_language_prefix`, `$timezone`, `$timezone_offset`, `$webview_app` | posthog-js at checkout (snapshot) | identical values to the buyer's browser events. Without a snapshot: parsed from the checkout request's user agent with posthog-js's own rules |
| `in_app_browser` | user agent | new: `Instagram`, `Facebook`, `TikTok`, … (posthog-js reports Instagram's iOS browser as "Mobile Safari") |
| `$current_url`, `$pathname`, `$host`, `$referrer`, `$referring_domain` | posthog-js at checkout | the checkout page where the order was placed (the previous plugin's purchase carried the thank-you page URL, including the order key) |
| `$session_id`, `$window_id`, `$pageview_id`, `$device_id` | posthog-js at checkout | session only while still running (within an hour of checkout and 24 h of its start) |
| `$active_feature_flags`, `checkout_flags` | posthog-js stored flags at checkout | `checkout_flags` maps flag → variant (audit only) |
| `$ip` | WooCommerce's customer IP (checkout request) | buyer location via GeoIP; `$geoip_disable` when unknown or private |
| `identity_source`, `identity_reason`, `consent`, `tracking_source` (`server`), `platform`, `store`, `schema_version`, `$lib`, `$lib_version` | plugin | provenance |
| `$process_person_profile` | person mode of the visitor at checkout | `false` for anonymous visitors (as posthog-js sends their events) |

## Other server events

- `Order Refunded`: `order_id`, `order_number`, `refund_id`, `refund_amount` (0.2.x name; always the amount refunded), `total` and `revenue` (the refund amount, positive; 0 with `after_cancellation: true` when the order's cancellation already reversed it), `order_total`, `full_refund`, `reason`, `currency`, `products[]` (refunded lines; `price` per unit), `products_string`, `payment_method`; same distinct ID and person mode as the purchase.
- `Order Cancelled`: `order_id`, `order_number`, `total` and `revenue` (what refunds had not reversed), `order_total`, `currency`, `products_string`, `payment_method`; timestamped at the status change.
- `Payment Failed`: `order_id`, `order_number`, `failure_status` (`failed` / `cancelled`), `attempt`, `minutes_since_order`, `total`, `currency`, `payment_method`, `payment_method_title`, `products[]`, `products_string`.
- `Checkout Started`: as the previous plugin (`affiliation`, `value`, `revenue` = cart total, `shipping`, `tax`, `discount`, `coupon`, `currency`, `products[]`, `products_string`), plus `subtotal`, `quantity`, `checkout_id` (cart hash), `checkout_type` (`funnelkit` / `classic` / `block`), request URL and referrer, buyer IP, user agent, `$session_id` and `$device_id` from the cookie, and `speculative_load: true` when the browser requested the page as a prefetch (WordPress's speculative loading); since 0.6.0 such an event is sent only once the page is shown.
- `Product Added` / `Product Removed`: `product_id`, `variant`, `sku`, `name`, `category`, `price`, `quantity`, `value`, `currency`, `url`, `image_url`, `cart_id`, `source` (`checkout_page` when a funnel checkout fills the cart as it loads, not sent as `Product Added` by default since 0.6.0; `ajax`, `url_or_form`, `store_api`, `other`), and `speculative_load` as for `Checkout Started`. The previous plugin listened to browser button events and missed cart changes made by funnels and blocks.
- `Coupon Applied` / `Coupon Removed`: as the previous plugin (`coupon_id` = code, `coupon_name`, `discount`, `cart_id`, `currency`), recorded when WooCommerce applies it rather than from AJAX responses.

## Browser events

- posthog-js events as configured (pageview, page leave, autocapture, dead clicks), with posthog-js's own properties.
- `View Category` (`page_type`, `category_id`, `category_name`, `category_slug`, `currency`) and `Cart Viewed` (`page_type`, `total`, `currency`, `item_count`, `products[]`, `products_string`): as the previous plugin.
- `Product Viewed` (`product_id`, `sku`, `name`, `category`, `price`, `currency`, `url`, `image_url`, `product_type`): replaces the previous plugin's `Product List Viewed` on product pages.
- `Order Confirmation Viewed` (`order_id`, `return_context` = `same_browser` / `new_browser_continued` / `known_browser_alias_sent` / `identified_browser_not_joined` / `not_linked`, `link_reason`): new.
- Every browser event passes `before_send`, which removes order keys, e-mail addresses and the configured URL parameters from every string at any depth (URLs, web vitals, heatmap keys, autocapture's element chain). On order-received, order-pay and account pages autocapture records no element text or attributes, and replay and heatmaps are off.

## Product properties (1.1.0)

Every product in `products[]` (purchases, refunds, failed payments, Checkout Started, Cart Viewed) and every product event (`Product Added`, `Product Removed`, `Product Viewed`) carries, besides the fields above, everything WooCommerce shows about the product. Fields already listed keep their names and values; nothing below overwrites them.

| Property | Content |
| --- | --- |
| `product_type` | `simple`, `variable`, `variation`, `grouped`, `external` or a plugin's type |
| `parent_name`, `parent_sku` | for a variation: the product it belongs to |
| `variant_url` | for a variation: its own address with its attributes in the query (`?attribute_pa_bundle=2-x-product`), as the previous plugin's `url` |
| `attributes` | attribute name → value as shoppers see it: the variation's choices first (`{"pa_bundle": "2 x Product"}`), then the product's other visible attributes (options comma-separated). Global attributes are keyed `pa_<slug>`, custom ones by their sanitized name. Hidden attributes that vary nothing are left out |
| `categories`, `tags`, `brands` | all term names (`category` stays the first category) |
| `shipping_class` | the shipping class name |
| `on_sale` | whether the sale price applies now |
| WooCommerce product data | every other field of `WC_Product::get_data()`, read as the shop shows it (a variation inherits what it does not set): `slug`, `status`, `featured`, `catalog_visibility`, `global_unique_id` (GTIN, EAN …), `regular_price`, `sale_price` (numbers), `date_on_sale_from`/`_to`, `date_created`, `date_modified` (UTC), `total_sales`, `tax_status`, `tax_class`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `low_stock_amount`, `sold_individually`, `weight`, `length`, `width`, `height` (numbers), `upsell_ids`, `cross_sell_ids`, `reviews_allowed`, `menu_order`, `virtual`, `downloadable`, `download_limit`, `download_expiry`, `rating_counts`, `average_rating`, `review_count`. Empty fields are left out |
| `custom_fields` | product custom fields (meta), only with Events → Product custom fields set to *visible* (keys without a leading underscore) or *all*; WooCommerce's own cost-of-goods fields (`cogs_*`, a purchase cost) are left out of the WooCommerce data above unless it is *all*; a variation's own fields win over its parent's; at most 100, values cut at 500 characters; lists and objects as JSON when they fit |

Never sent: descriptions, the purchase note, download file links, the post password, term and image IDs.

Data printed into a page for the browser module (`Product Viewed`, `Cart Viewed`) is the same for every visitor and visible in the page source, so it never carries `custom_fields`, cost of goods (`cogs_*`), `total_sales`, `stock_quantity` or `low_stock_amount`.

Developers can change the set with the `w99_phwc_product_properties` filter (see [developers](developers.md#filters)).

## Deliberate differences from the previous plugin

| Previous plugin | 99Trace | Why |
| --- | --- | --- |
| `identify(wp_hash(email))` on checkout e-mail input, Elementor forms and thank-you pages; person properties `email`, `name`, `orders_count`, `total_spent` | No e-mail identity; logged-in accounts identified with `wc:<host>:<id>`; no personal person properties | Typed e-mails are unverified and merge unrelated shoppers; the hash endpoint let any visitor obtain any e-mail's person ID; identify mid-checkout re-hashes distinct-ID-bucketed flags; plain e-mails and names reached PostHog |
| `person_profiles: 'always'` | `always` (setting; `identified_only` available) | Every buyer has a person profile with browser and server events on it; purchases mirror the visitor's mode |
| Purchase from the thank-you page (browser) | Purchase from the server when paid | The thank-you page is missed after bank-app returns and repeated on reloads |
| `Checkout Started` once per browser tab session | once per cart contents per 30 minutes, server-side | reloads and ad blockers no longer change the count |
| `$current_url` of purchases contains the order key (`url_key` via a URL transformation) | order keys stripped everywhere | an order key opens the order's details |
| `Elementor Popup Opened` / `Form Submitted`, `View Shop`, `View Tag`, `View Post` | not sent | rarely used; autocapture covers clicks; add on request |

## Consumers to check before switching a store over

- PostHog destinations and transformations that read `email`, `name` or `wp_hash` person IDs (for example a Meta Conversions API destination matching on e-mail). Click IDs, IP, user agent and URL are on the server events; e-mail is not.
- Insights filtered on `$current_url` of `Order Completed` (now the checkout URL), or on `url_key`.
- Cohorts built from the `email` person property.
