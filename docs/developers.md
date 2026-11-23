# 99Trace for developers

Hooks, constants and stored names, for shops and agencies that adapt 99Trace with code. The settings page covers everything else. Hook names keep the `w99_phwc_` prefix of 99Trace's first versions, so code written for them keeps working.

## Filters

| Filter | Arguments | Default | Use |
| --- | --- | --- | --- |
| `w99_phwc_paid_statuses` | `string[] $statuses` | `wc_get_is_paid_statuses()` | Order statuses that count as paid and send `Order Completed`. Add a custom status that means "paid". |
| `w99_phwc_should_track_order` | `bool $track, WC_Order $order` | paid web order, paid after sending started | Leave orders out (wholesale, internal, test) or include special ones. |
| `w99_phwc_order_completed_event` | `array $event, WC_Order $order` | the built event | Add or change properties of the purchase before it is frozen. It runs once per order: the frozen event is what every retry sends. |
| `w99_phwc_store_id` | `string $host` | the site's host | The store identifier in events and event IDs. Change it only before the first event is sent: it is part of every event ID. |
| `w99_phwc_customer_distinct_id` | `string $id, int $user_id` | `wc:<store>:<user ID>` | The opaque distinct ID of a signed-in customer. Never return an e-mail address. |
| `w99_phwc_record_user` | `bool $record, int $user_id` | settings under Visitors & orders | Whether a signed-in user's visits are recorded. |
| `w99_phwc_track_staff` | `bool $track` | `false` | Record shop staff regardless of the setting. |
| `w99_phwc_posthog_js_config` | `array $options` | Browser features and the JSON setting | posthog-js options passed to `posthog.init`. Options 99Trace depends on (host, key, persistence, consent, `before_send`, `bootstrap` and others) are applied after this filter and cannot be changed. |
| `w99_phwc_product_properties` | `array $properties, WC_Product $product, string $for` | the product's public WooCommerce data | Product properties added to `products[]` lines and product events (1.1.0). `$for` is `server` for events the shop's server sends, `page` for data printed into the page (keep anything private out of `page`). Existing fields such as `product_id`, `sku` and `price` are set before this filter and cannot be changed through it. |
| `w99_phwc_allow_other_posthog_plugin` | `bool $allow` | `false` | Run the browser module although another PostHog plugin is active. Only when that plugin does not load posthog-js. |
| `w99_phwc_return_link_max_age` | `int $seconds, WC_Order $order` | one day | How long after an order its thank-you page may join a new browser to the buyer. |
| `w99_phwc_retry_delay` | `int $seconds, int $attempt, string $kind` | 1, 5 and 30 minutes, 2, 6 and 12 hours, 1 day | Delay before a failed order event is retried. |
| `w99_phwc_finish_request_first` | `bool $finish` | `true` | Send cart and checkout events after the response has gone to the shopper (`fastcgi_finish_request`). Turn off where finishing the request early breaks another plugin. |
| `w99_phwc_hold_speculative` | `bool $hold` | `true` | Hold server events of a page the browser loaded in advance until the page is shown. |

## Action

| Action | Arguments | When |
| --- | --- | --- |
| `w99_phwc_event_sent` | `array $event, WC_Order $order` | After PostHog accepted an order event (`Order Completed`, `Order Refunded`, `Order Cancelled`). |

## Constants

| Constant | Where | Effect |
| --- | --- | --- |
| `W99_TRACE_MODE` | `wp-config.php` | Forces the mode: `off`, `test` or `on`. Useful on staging copies. 99Hog's `W99_PHWC_MODE` is read when it is not set. |
| `W99_TRACE_VERSION`, `W99_TRACE_FILE`, `W99_TRACE_DIR` | defined by the plugin | Version, main file and folder. |

## Stored names

| What | Name |
| --- | --- |
| Options | `w99_phwc_*` (one per setting), `w99_phwc_event_names`, `w99_phwc_sending_since`, `w99_phwc_site_lock`, `w99_phwc_version` |
| Order meta | `_99w_ph_identity` (identity saved at checkout), `_99w_ph_capture` (checkout context), `_99w_ph_order_completed`, `_99w_ph_order_cancelled`, `_99w_ph_payment_failed`, and `_99w_ph_refund` on refunds (delivery state with the frozen event) |
| Scheduled tasks (Action Scheduler) | group `w99-phwc`; hooks `w99_phwc_send`, `w99_phwc_send_event`, `w99_phwc_sweep` |
| Cookies | `ph_wc_vid` (shared visitor ID), `ph_wc_customer` (signed-in customer's opaque ID), `ph_wc_logout`; posthog-js keeps its own `ph_<project key>_posthog` |
| WooCommerce AJAX | `?wc-ajax=w99_phwc_link` (joins an order placed before consent), `?wc-ajax=w99_phwc_shown` (a page loaded in advance was shown) |
| Store API | namespace `w99-phwc` on the checkout endpoint (the visitor's identity) |
| Logs | WooCommerce → Status → Logs, source `99trace` |

## Event IDs

Order events use UUID version 5 over `99hog:v1:woocommerce:<store>:<kind>:<ids>` in the namespace `ade32437-dad7-45f6-8919-68d855bb110c`; orders counted without a visitor use the distinct ID `99hog:order:<uuid>`. Both date from 99Trace's first name and never change, so an event retried after an update keeps its ID and PostHog collapses duplicates.
