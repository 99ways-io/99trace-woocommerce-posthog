# 99trace.com

The 99Trace website: one static page, served as an assets-only Cloudflare Worker (`99trace-site`) on the `99trace.com` zone of the 99Ways Cloudflare account. It sets no cookies and loads nothing from other domains.

| Path | What it holds |
| --- | --- |
| `src/index.html` | The page, with placeholders for the wordmark and the screenshots |
| `build.py` | Builds `public/index.html`, the screenshot images, favicons and the social image from `brand/`, `.wordpress-org/` and the plugin's `readme.txt` (screenshot captions). Needs Python 3 with Pillow |
| `public/` | What is served. `assets/site.css`, the fonts (Inter and IBM Plex Mono, SIL Open Font License) and the text files are edited here |
| `public/_headers` | Security headers for every response; a week's cache for `/assets/` |
| `public/_redirects` | Short links that stay valid across releases: `/download`, `/github`, `/changelog`, `/security` |
| `cloudflare.config.ts` | The Worker: name, custom domains `99trace.com` and `www.99trace.com`, the 404 page, no `workers.dev` or preview URLs |
| `wrangler.config.ts`, `package.json` | Build settings for the `cf` CLI, which builds this project with Wrangler |

```sh
python3 website/build.py                 # rebuild after changing src/, brand/ or the screenshots
cd website && npm ci                     # cf and wrangler, pinned
cd website && cf deploy --dry-run        # build and check without uploading
cd website && cf deploy                  # publish (cf auth whoami must show the 99Ways account)
```

Zone settings, set once with `cf` and not part of the deploy: Always Use HTTPS, minimum TLS 1.2, automatic HTTPS rewrites, and a redirect rule in the `http_request_dynamic_redirect` phase that sends `www.99trace.com` to `https://99trace.com` with the path and query.

Keep the page in step with the plugin: requirements, events and settings come from the plugin's `readme.txt` and `README.md`. State limits openly and quote no figure we did not measure (see `brand/README.md`, Voice).
