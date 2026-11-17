// 99trace.com: an assets-only Worker on the 99trace.com zone of the 99Ways Cloudflare account.
// Deploy with the cf CLI from website/: `cf deploy`. Headers and short links are in public/_headers
// and public/_redirects; the www-to-apex redirect and HTTPS-only are zone rules (see README.md).
import { defineConfig } from "cf/config";

export default defineConfig({
  worker: {
    name: "99trace-site",
    compatibilityDate: "2026-11-01",
    assets: {
      notFoundHandling: "404-page",
    },
    domains: ["99trace.com", "www.99trace.com"],
    workersDev: false,
    previewUrls: false,
  },
});
