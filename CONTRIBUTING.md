# Contributing to 99Trace

Thank you for helping. 99Trace exists to get every WooCommerce order into PostHog exactly once, on the right visitor; contributions are judged by whether they keep that promise.

## Before you start

- **Bugs:** open an issue with your WordPress, WooCommerce, PHP and 99Trace versions, the checkout and payment method involved, and what PostHog received compared with what WooCommerce recorded. A copy of the 99Trace box on the affected order (with personal data removed) helps most.
- **Features:** open an issue first and describe the measurement problem it solves. Changes to identity, consent or delivery need a design discussion before code.
- **Security issues:** never in a public issue. See [SECURITY.md](SECURITY.md).

## Development

You need PHP 8.0+ and Node 24+.

```sh
php tests/unit/run.php                          # PHP unit tests
for t in tests/unit/*.mjs; do node "$t"; done   # browser module unit tests
tests/run.sh                                    # WordPress Playground: latest WordPress and WooCommerce, PHP 8.4, HPOS
bin/build-zip.sh                                # the zip WordPress.org receives
```

`tests/run.sh` starts a throwaway WordPress in WordPress Playground and intercepts every request to PostHog; nothing leaves your machine except the WordPress, WooCommerce and Playground downloads.

## Rules for changes

- **A test for every behaviour.** A fix comes with the test that failed before it.
- **Exactly once stays exactly once.** Never change the UUID scheme (`Uuid::event`), the anonymous-order prefix or the frozen event payloads of orders that are already queued.
- **Stored names never change.** Options, order meta, scheduled tasks and cookies keep the `w99_phwc_` / `ph_wc_` names, so existing shops keep their data.
- **Escape late, sanitise early, check capabilities and nonces.** Plugin Check must stay free of errors and warnings in every category.
- **Translate every admin string** with the `99trace-posthog-for-woocommerce` text domain, with a translators' comment for each placeholder.
- **No personal data in events or URLs.** E-mail addresses, order keys and postal addresses never reach PostHog except as the documented person properties.
- **No customer data, project keys or shop names** in code, tests, fixtures or commit messages.
- **Write for shop owners.** Settings explain what happens, in plain words, on the page itself.

## Pull requests

Keep each pull request to one change, describe what it changes for a shop owner, update `CHANGELOG.md` and the readme's changelog when users will notice it, and make sure CI passes. Maintainers review within a few working days.

By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the plugin's license.
