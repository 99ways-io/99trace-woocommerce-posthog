#!/bin/sh
# Build dist/99trace-integration-with-posthog-for-woocommerce-<version>.zip: the 99trace-integration-with-posthog-for-woocommerce folder exactly as WordPress.org and users receive it.
# Fails when the version in the plugin header, W99_PHWC_TRACE_VERSION and the readme's Stable tag disagree.
set -eu
cd "$(dirname "$0")/.."

header=$(sed -n 's/^ \* Version: *//p' 99trace-integration-with-posthog-for-woocommerce/99trace-integration-with-posthog-for-woocommerce.php | tr -d ' \r')
constant=$(sed -n "s/^define( 'W99_PHWC_TRACE_VERSION', '\(.*\)' );/\1/p" 99trace-integration-with-posthog-for-woocommerce/99trace-integration-with-posthog-for-woocommerce.php)
stable=$(sed -n 's/^Stable tag: *//p' 99trace-integration-with-posthog-for-woocommerce/readme.txt | tr -d ' \r')
if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch: header $header, W99_PHWC_TRACE_VERSION $constant, readme Stable tag $stable" >&2
	exit 1
fi
if ! grep -q "^= $header =" 99trace-integration-with-posthog-for-woocommerce/readme.txt; then
	echo "readme.txt has no changelog entry for $header" >&2
	exit 1
fi

rm -rf dist
mkdir -p dist
zip -rqX "dist/99trace-integration-with-posthog-for-woocommerce-$header.zip" 99trace-integration-with-posthog-for-woocommerce -x '*.DS_Store' -x '99trace-integration-with-posthog-for-woocommerce/.*' -x '*/node_modules/*'
echo "dist/99trace-integration-with-posthog-for-woocommerce-$header.zip"
unzip -l "dist/99trace-integration-with-posthog-for-woocommerce-$header.zip" | tail -n 1
