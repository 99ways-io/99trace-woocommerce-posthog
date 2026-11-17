=== 99Trace – PostHog Analytics for WooCommerce ===
Contributors: 99ways
Tags: posthog, woocommerce, analytics, server-side tracking, ab testing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate WooCommerce with PostHog in one click. Track customer activity with unified user identities across server and client sides.

== Description ==

Most PostHog setups for WooCommerce record a purchase from the thank-you page, in the buyer's browser. That page is where tracking breaks:

* **Payment redirects.** Buyers pay in a banking app or on a payment page and come back in another browser, where PostHog does not know them or is still waiting for cookie consent.
* **In-app browsers.** A shopper browses inside Instagram or Facebook, pays, and lands in Safari or Chrome: one buyer, two visitors.
* **Ad blockers.** When PostHog is blocked, the purchase is never recorded.
* **Reloads.** A reloaded or reopened thank-you page records the same order again.

Because the loss depends on payment method and device, it can bias an A/B test, not only shrink it.

99Trace sends what matters from your shop's server instead.

* **Every paid order, exactly once.** `Order Completed` is sent by your shop's server when WooCommerce marks the order paid, timestamped at the payment, with an event ID derived from the order. Retries and reloads never count it twice.
* **On the right visitor.** The buyer's PostHog identity is saved with the order at checkout, before any redirect, and the purchase is sent under it, whichever browser the buyer finishes in.
* **Refunds, cancellations and failed payments**, also from the server, so revenue in PostHog follows WooCommerce.
* **Cart and checkout events** (`Product Added`, `Product Removed`, `Coupon Applied`, `Coupon Removed`, `Checkout Started`) at the moment WooCommerce changes the cart, also when PostHog is blocked in the browser.
* **One visitor ID for browser and server.** posthog-js and your shop share one first-party visitor ID, so browser and server events land on the same person without merging.
* **Safe for experiments.** Feature flags and experiments keep working, and the flags a buyer had at checkout are copied onto the purchase for auditing.
* **Coverage report.** WooCommerce → Status → 99Trace shows how many paid orders were sent and how many are linked to a visitor, by payment method.

= PostHog in the browser, optional =

Switch on the browser module and 99Trace runs posthog-js for you: page views, page leave, autocapture, heatmaps, web vitals, session replay, surveys and error tracking, each following your PostHog project's settings or overridden from WordPress. On order and account pages, replay, heatmaps and element text are always off, and order keys and e-mail addresses are removed from every event.

Already have PostHog on the site through another plugin? 99Trace can send its server events beside it first, reading the same visitor, and take over the browser when you are ready.

= Consent: your decision, every option supported =

Track every visitor, or wait for "statistics" consent from Cookiebot or from any cookie plugin that uses the WP Consent API. Before the visitor answers, PostHog can wait; record the visit in the page's memory and send it only on consent; or start switched off, so experiments get their variant at once without recording anything. Orders placed without consent can be counted anonymously (no visitor ID, IP address or person) or not sent at all.

= Built for real shops =

* Block and classic checkout, HPOS (custom order tables), and custom checkouts such as FunnelKit.
* Test mode works out every event and writes it to the WooCommerce log without sending anything.
* A copy of your shop (staging, a developer's machine) never sends into your live project: sending stops when the site's address changes, until an administrator confirms.
* Shop staff, page editors and page-builder previews are not recorded by default.
* Every event can be switched off or renamed to match an existing PostHog setup.
* Personal data export and erasure through WordPress and WooCommerce, and a suggested privacy policy text that follows your settings.
* Translation-ready.

= Events =

From your shop's server: Order Completed, Order Refunded, Order Cancelled, Payment Failed, Checkout Started, Product Added, Product Removed, Coupon Applied, Coupon Removed.

From the browser module: Product Viewed, View Category, Cart Viewed and Order Confirmation Viewed, beside PostHog's own `$pageview`, `$pageleave`, `$autocapture` and `$dead_click`.

Event and property names follow PostHog's ecommerce events. Amounts are numbers; `revenue` is the amount charged and `net_revenue` the merchandise after discounts.

= Requirements =

* WooCommerce 8.7 or later.
* A PostHog project: PostHog Cloud (EU or US) or self-hosted PostHog. PostHog has a free tier.

99Trace is built by [99Ways](https://99ways.io) and is open source: [github.com/99ways-io/99trace-woocommerce-posthog](https://github.com/99ways-io/99trace-woocommerce-posthog). It is not affiliated with or endorsed by PostHog Inc. or by WooCommerce and Automattic. PostHog and WooCommerce are trademarks of their owners.

== External services ==

99Trace connects your shop to [PostHog](https://posthog.com), the product analytics service whose project key you enter in the settings. It sends nothing until you enter a project key and set the mode to On. 99Trace sends nothing to 99Ways or to anyone other than the PostHog project you configure.

**From your shop's server** to the PostHog host in the settings (`https://eu.i.posthog.com`, `https://us.i.posthog.com`, your self-hosted PostHog or your own proxy):

* When an order is paid, refunded or cancelled, or its payment fails: the order's ID and number, its products (ID, SKU, name, category, price, quantity), totals, tax, shipping, discounts, coupons, currency and payment method; the buyer's PostHog visitor, device and session IDs and active feature flags as saved at checkout; the address of the checkout page and the campaign parameters it carried; the buyer's IP address and browser (user agent), unless the order has no consent or "Locate buyers by their own IP address" is off.
* When a product is added to or removed from the cart, a coupon is applied or removed, or the checkout is opened: the product or coupon and the cart's value, under the visitor's ID, with the visitor's IP address and browser.
* With "Customer details on the person" on, and only for visitors who may be recorded: the buyer's e-mail address, first and last name and phone number, as properties of their person in PostHog.
* Never: postal addresses, order keys, or customer details of an order counted anonymously.

**In the visitor's browser**, only with "Run PostHog in the browser" on: 99Trace loads PostHog's JavaScript library (posthog-js) from PostHog's servers (`eu-assets.i.posthog.com` or `us-assets.i.posthog.com`, or your own proxy). posthog-js sends page views, clicks and the other events you switch on to the same PostHog host, and asks it which feature flags apply. The visitor's IDs are kept in first-party cookies: `ph_*` (posthog-js) and `ph_wc_vid`, `ph_wc_customer` and `ph_wc_logout` (99Trace).

PostHog's [terms of service](https://posthog.com/terms), [privacy policy](https://posthog.com/privacy) and [data processing agreement](https://posthog.com/dpa).

== Installation ==

1. Install and activate 99Trace. WooCommerce must be active.
2. Open WooCommerce → 99Trace (also under WooCommerce → Settings → 99Trace).
3. Under Connect, enter your PostHog project API key (PostHog → Settings → Project → Project API key; it starts with `phc_`) and your PostHog host (EU or US Cloud).
4. Set the mode to Test and place a test order: WooCommerce → Status → Logs (source `99trace`) lists every event that would have been sent.
5. Choose how PostHog handles consent under Consent.
6. If PostHog already runs on your site through another plugin or a code snippet, remove it, then switch on "Run PostHog in the browser". Keep the same project key and every visitor stays the same person.
7. Set the mode to On.

== Frequently Asked Questions ==

= Do I need a PostHog account? =

Yes: a project on PostHog Cloud (EU or US) or a self-hosted PostHog. PostHog's free tier covers many shops.

= PostHog already runs on my site. Will anything be counted twice? =

No. While another PostHog plugin is active, 99Trace never loads posthog-js, because two copies on one page split every visitor in two; it sends only its server events, under the visitor the other plugin's cookie names. Remove the other plugin (or your snippet) and switch on "Run PostHog in the browser" when you are ready. Purchases paid before 99Trace started sending to your project are never sent again.

= How does 99Trace know which visitor placed an order? =

At checkout, the browser module copies the visitor's PostHog identity into the order, and the server also reads it from the visitor's cookies. 99Trace saves it with the order before the buyer leaves for the payment page, and every later event about the order uses it. When the buyer returns in another browser, opening the thank-you page joins that browser to the same visitor.

= Does it work with the block checkout, HPOS and custom checkouts? =

Yes. Block and classic checkouts, WooCommerce's custom order tables (HPOS) and checkout plugins such as FunnelKit are supported; a checkout that does not run the browser module still gets the visitor from the cookie.

= Will it slow down my shop? =

No noticeable change. Cart and checkout events are sent after the page has gone to the shopper. Order events are sent in the background by WooCommerce's Action Scheduler. posthog-js loads asynchronously, as PostHog's own snippet does.

= What happens when PostHog cannot be reached? =

Order events are retried for about two days, and an hourly check queues any paid order that was missed. Each retry sends the same event with the same ID, so nothing is counted twice. Cart and checkout events are kept and retried as well.

= Can I try it without sending data? =

Yes. In Test mode, 99Trace works out every event and writes it to WooCommerce → Status → Logs without sending anything, and PostHog runs in the browser without recording.

= What about staging sites? =

99Trace remembers the address sending was switched on for. A copy of your shop at another address stops sending and shows a notice, so test orders never reach your live PostHog project. An administrator can confirm a real move with one click.

= Does 99Trace make my shop GDPR compliant? =

No plugin can do that on its own: what you need depends on your country, your shop and what you record. 99Trace gives you the controls: consent modes for Cookiebot and the WP Consent API, orders counted without any identifier, switches for IP addresses and customer details, exclusion of staff, personal data export and erasure, a suggested privacy policy text, and the choice of PostHog's EU or US Cloud.

= Can I rename events to match my existing PostHog setup? =

Yes. Under Events, each event 99Trace sends has a switch and an optional name. A renamed event is still sent exactly once.

= I used "99Hog for WooCommerce". How do I switch? =

99Trace is the new name of 99Hog for WooCommerce. Install and activate 99Trace, deactivate 99Hog (99Trace waits, with a notice, while 99Hog is active), then clear your page cache. Settings, queued events and visitor IDs carry over. Server events now carry `$lib` `99trace` instead of `99hog-woocommerce`.

= Where do I report a bug or a security issue? =

Bugs and ideas: [GitHub issues](https://github.com/99ways-io/99trace-woocommerce-posthog/issues). Security issues: privately, as described in [SECURITY.md](https://github.com/99ways-io/99trace-woocommerce-posthog/blob/main/SECURITY.md).

== Screenshots ==

1. Connect: your PostHog project, the mode, and PostHog in the browser.
2. Consent: track every visitor or follow Cookiebot or the WP Consent API, and what happens before the visitor answers.
3. Events: every event 99Trace sends, with a switch and an optional name in PostHog.
4. Visitors & orders: shop staff, person profiles, signed-in customers and one visitor ID for browser and server.
5. Each order shows the PostHog identity saved at checkout and the delivery of every event.
6. The coverage report: paid orders sent and linked to a visitor, by payment method.

== Changelog ==

= 1.0.0 =
* First public release. 99Trace was piloted on live shops under the name "99Hog for WooCommerce" (0.2 to 0.6).
* New: translation-ready (text domain `99trace-posthog-for-woocommerce`).
* New: a suggested privacy policy text under Settings → Privacy → Policy Guide, following your settings.
* New: uninstalling removes 99Trace's settings and scheduled tasks. Order data stays with the orders, so a reinstall never sends an order twice.
* New: while 99Hog for WooCommerce is still active, 99Trace waits and shows how to switch.
* Changed: server events carry `$lib` `99trace` (was `99hog-woocommerce`).
* Changed: the developer filter for posthog-js options can no longer replace the options 99Trace's identity, consent and privacy handling depend on.
* Fixed: the coverage report now counts orders that a gateway marks paid without a payment date, such as cash on delivery. 99Trace already sent their purchases.

== Upgrade Notice ==

= 1.0.0 =
First public release. Coming from 99Hog for WooCommerce: activate 99Trace, then deactivate 99Hog. Settings, queued events and visitor IDs carry over.
