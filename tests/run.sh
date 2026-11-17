#!/usr/bin/env bash
# Runs the plugin tests on a throwaway WordPress + WooCommerce (WordPress Playground, in Node).
# Nothing leaves this machine except downloads of WordPress, WooCommerce and Playground; all PostHog requests are intercepted.
set -euo pipefail
cd "$(dirname "$0")"
rm -f results.txt
npx -y @wp-playground/cli@3.1.64 run-blueprint --blueprint=blueprint.json \
  --mount=../99trace-posthog-for-woocommerce:/wordpress/wp-content/plugins/99trace-posthog-for-woocommerce \
  --mount=.:/tests
cat results.txt
grep -q '^ALL PASSED' results.txt
