# 99trace.com

The 99Trace website: one static page served by a Cloudflare Worker on the `99trace.com` zone of the 99Ways Cloudflare account. It sets no cookies and loads nothing from other domains.

| Path | What it holds |
| --- | --- |
| `src/index.html` | The page, with placeholders for the wordmark and the screenshots |
| `build.py` | Builds `public/index.html`, the screenshot images, favicons and the social image from `brand/`, `.wordpress-org/` and the plugin's `readme.txt` (screenshot captions) |
| `public/` | What is served. `assets/site.css`, the fonts (Inter and IBM Plex Mono, SIL Open Font License) and the text files are edited here |
| `src/worker.js` | Redirects `www` and `http` to `https://99trace.com`, short links (`/download`, `/github`, `/changelog`, `/security`), security headers |
| `wrangler.jsonc` | The Worker and its custom domains |

```sh
python3 website/build.py                        # (needs Pillow) rebuild after changing src/, brand/ or the screenshots
cd website && npx wrangler@4.162.0 dev          # preview on http://localhost:8787
cd website && npx wrangler@4.162.0 deploy       # publish (needs `wrangler login` with access to the 99Ways account)
```

Keep the page in step with the plugin: requirements, events and settings come from the plugin's `readme.txt` and `README.md`. State limits openly and quote no figure we did not measure (see `brand/README.md`, Voice).
