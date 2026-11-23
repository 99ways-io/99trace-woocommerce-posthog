# 99Trace brand

99Trace is a product of 99Ways, and its identity is built from the 99Ways identity: the same "99" whose paired 9s read as eyes, the same lettering, the same five colours. One element is 99Trace's own: **the trace**, a dotted path that ends in a green dot. It stands for the buyer's journey (in-app browser, bank app, another browser) ending in the order, which 99Trace sends once and on the right visitor.

Status: proposed with the 1.0.0 release, for 99Ways' approval. The 99Ways kit governs the "99" itself; nothing here changes it.

## Name

- Write **99Trace**: one word, capital T. In running text: "99Trace", never "99trace", "99 Trace" or "99TRACE".
- The WordPress.org slug, plugin folder and text domain are `99trace-integration-with-posthog-for-woocommerce`. Short identifiers (the settings tab, the log source, `$lib`) are `99trace`. The public repository is `99ways-io/99trace-woocommerce-posthog`, and the website is [99trace.com](https://99trace.com) (`website/`).
- With a descriptor: **99Trace: Easy Integration with PostHog for WooCommerce** (WordPress.org title). The distinctive name always comes first, and a platform's name appears only after a connecting word: "with PostHog", "for WooCommerce". WordPress.org's upload page accepts "WooCommerce" only at the very end, as "for WooCommerce" (its trademark check, 23 Nov 2026), and its 21 Nov 2026 review asked for a connector before "PostHog"; never "WooTrace", "PostHog Trace" or similar, which Woo's and PostHog's trademark rules forbid.
- Mention once per page that 99Trace is not affiliated with PostHog Inc. or with WooCommerce and Automattic.

## Logo

| File | Use |
| --- | --- |
| `logo/99trace-wordmark-light-background.svg` | Default, on Warm White or another quiet light field |
| `logo/99trace-wordmark-dark-background.svg` | On Graphite or another quiet dark field |
| `logo/99trace-wordmark-one-color-black.svg`, `-white.svg` | When colour printing or rendering is unavailable |
| `logo/png/` | Raster wordmarks with the 25-unit clear space |
| `icon/99trace-icon.svg` (+ 512 and 1024 px PNG) | Square contexts: WordPress.org, GitHub, app icons, avatars |

Construction follows the 99Ways logo specification exactly: cap height 100 units; the two custom 9s and their Inquiry-blue pupils copied from the 99Ways compact master; IBM Plex Mono SemiBold 600 outlines for "Trace"; a 10-unit gap from the second 9 to the T, and 0-unit gaps between the visible bounds of consecutive letters. The build rebuilds "Ways" with the same method and stops unless it matches the 99Ways master to 0.05 units.

Keep a clear space of 25 units (a quarter of the cap height) around the wordmark, and a visible width of at least 180 px. Do not retype, respace, stretch or recolour it, do not move the pupils, and do not put green on the logo itself.

## Colour

The 99Ways palette, used with the same meaning.

| Role | Light field | Dark field | 99Trace use |
| --- | --- | --- | --- |
| Graphite / Warm White | `#292B2A` | `#F4F3EF` | Text, logo body, backgrounds |
| Muted | `#676B65` | `#B5B8B1` | Secondary text, the trace's dots |
| Inquiry blue | `#3558B8` | `#A6BAFF` | The pupils; the journey's stops (observing) |
| Outcome green | `#147D40` | `#6BD98D` | The order at the end of the trace: the result 99Trace protects |

Green marks a favourable outcome only (an order captured, a check passed); it never marks a feature, a button for its own sake, or the logo.

## Type

- **Logo:** IBM Plex Mono SemiBold, outlined (no font needed to show it).
- **Headlines and text:** Inter, 600 for headlines, 400 for text.
- **Labels, event names, code:** IBM Plex Mono (`Order Completed`, `$pageview`).

## Voice

Plain, exact and calm, as in the plugin's settings: say what happens, when, and to which visitor. Lead with the outcome the shop owner cares about ("every order, exactly once, on the visitor who placed it"), give the mechanism when asked, and state limits openly. Never promise legal compliance or numbers we did not measure.

## Assets for WordPress.org and GitHub

- `../.wordpress-org/`: `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1.png` … `screenshot-6.png`, in the file names the plugin directory expects. The release workflow copies them to the SVN `assets` folder.
- `social/99trace-social-preview-1280x640.png`: GitHub repository → Settings → Social preview.

## Rebuilding

`build/build.sh` regenerates every asset above from the 99Ways master geometry (embedded in `build/wordmark.py`), the bundled IBM Plex Mono SemiBold (`build/font/`, SIL Open Font License) and Inter. It needs Python 3 with `fonttools` and Node 20 or later.

### Screenshots

`build/screenshots/` recreates the six WordPress.org screenshots: `sandbox-blueprint.json` starts a local WordPress Playground shop (WooCommerce, three products, offline payment methods, and stand-ins that keep every PostHog request on the machine), and `screenshots.mjs` captures the screens with Playwright at 2× after two guest orders have been placed, one paid on delivery and one by bank transfer marked paid, with a partial refund.

```sh
npx @wp-playground/cli@3.1.65 server --port=4401 --login \
  --blueprint=brand/build/screenshots/sandbox-blueprint.json \
  --mount=99trace-integration-with-posthog-for-woocommerce:/wordpress/wp-content/plugins/99trace-integration-with-posthog-for-woocommerce
node brand/build/screenshots/screenshots.mjs <folder with playwright installed> .wordpress-org
```
