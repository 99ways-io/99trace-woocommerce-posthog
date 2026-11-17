// 99trace.com on Cloudflare Workers: static assets from ../public, plus redirects and security headers.
const SITE = "99trace.com";
const REPO = "https://github.com/99ways-io/99trace-woocommerce-posthog";

// Short links that stay valid across releases.
const LINKS = {
  "/download": `${REPO}/releases/latest`,
  "/github": REPO,
  "/changelog": `${REPO}/blob/main/CHANGELOG.md`,
  "/security": `${REPO}/security/policy`,
};

const HEADERS = {
  "Content-Security-Policy":
    "default-src 'none'; img-src 'self'; style-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
  "Strict-Transport-Security": "max-age=31536000; includeSubDomains",
  "X-Content-Type-Options": "nosniff",
  "Referrer-Policy": "strict-origin-when-cross-origin",
  "Permissions-Policy": "camera=(), microphone=(), geolocation=(), interest-cohort=()",
  "Cross-Origin-Opener-Policy": "same-origin",
};

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // One address: https://99trace.com (www and plain http redirect there). The scheme comes from
    // Cloudflare's headers, so `wrangler dev`, which reports the production host over http, is not redirected.
    const insecure =
      request.headers.get("x-forwarded-proto") === "http" ||
      (request.headers.get("cf-visitor") || "").includes('"scheme":"http"');
    if (url.hostname === `www.${SITE}` || (url.hostname === SITE && insecure)) {
      url.hostname = SITE;
      url.protocol = "https:";
      return Response.redirect(url.toString(), 301);
    }

    const path = url.pathname.replace(/\/+$/, "") || "/";
    if (Object.hasOwn(LINKS, path)) {
      return Response.redirect(LINKS[path], 302);
    }

    const response = await env.ASSETS.fetch(request);
    const headers = new Headers(response.headers);
    for (const [name, value] of Object.entries(HEADERS)) headers.set(name, value);
    if (url.pathname.startsWith("/assets/")) {
      headers.set("Cache-Control", "public, max-age=604800");
    }
    return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
  },
};
