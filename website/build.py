#!/usr/bin/env python3
"""Build 99trace.com into website/public from website/src and the repository's brand assets.

Writes public/index.html (wordmark and screenshots filled in), the screenshot images (WebP, 1280 and
2560 px wide), the favicon set and the social image. Fonts, site.css and the small text files in
public/ are kept as they are. Needs Python 3 with Pillow (WebP support).

Usage: python3 website/build.py
"""
import html
import re
import shutil
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / "website"
PUBLIC = SITE / "public"
ASSETS = PUBLIC / "assets"
README = ROOT / "99trace-posthog-for-woocommerce" / "readme.txt"


def wordmark_paths():
    svg = (ROOT / "brand/logo/99trace-wordmark-light-background.svg").read_text()
    paths = dict((fill.upper(), d) for fill, d in re.findall(r'<path fill="(#[0-9A-Fa-f]{6})" d="([^"]+)"', svg))
    return paths["#292B2A"], paths["#3558B8"]


def screenshot_captions():
    text = README.read_text()
    section = text.split("== Screenshots ==", 1)[1].split("==", 1)[0]
    return [line.split(". ", 1)[1].strip() for line in section.strip().splitlines() if re.match(r"^\d+\. ", line)]


def build_screenshots(captions):
    out = ASSETS / "screenshots"
    out.mkdir(parents=True, exist_ok=True)
    figures = []
    for number, caption in enumerate(captions, start=1):
        source = ROOT / ".wordpress-org" / f"screenshot-{number}.png"
        image = Image.open(source).convert("RGB")
        for width in (1280, 2560):
            height = round(image.height * width / image.width)
            resized = image if width == image.width else image.resize((width, height), Image.LANCZOS)
            resized.save(out / f"screenshot-{number}-{width}.webp", "WEBP", quality=82, method=6)
        height = round(image.height * 1280 / image.width)
        alt = html.escape(caption, quote=True)
        figures.append(
            f'<figure><a href="/assets/screenshots/screenshot-{number}-2560.webp">'
            f'<img src="/assets/screenshots/screenshot-{number}-1280.webp" '
            f'srcset="/assets/screenshots/screenshot-{number}-1280.webp 1280w, /assets/screenshots/screenshot-{number}-2560.webp 2560w" '
            f'sizes="(max-width: 700px) 100vw, 540px" width="1280" height="{height}" loading="lazy" decoding="async" alt="{alt}"></a>'
            f"<figcaption>{html.escape(caption)}</figcaption></figure>"
        )
    return "\n      ".join(figures)


def build_icons():
    shutil.copyfile(ROOT / "brand/icon/99trace-icon.svg", PUBLIC / "favicon.svg")
    icon = Image.open(ROOT / "brand/icon/99trace-icon-1024.png").convert("RGBA")
    icon.resize((180, 180), Image.LANCZOS).save(PUBLIC / "apple-touch-icon.png", optimize=True)
    icon.resize((64, 64), Image.LANCZOS).save(PUBLIC / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])
    shutil.copyfile(ROOT / "brand/social/99trace-social-preview-1280x640.png", ASSETS / "og.png")


def main():
    body, pupils = wordmark_paths()
    page = (SITE / "src/index.html").read_text()
    page = page.replace("{{WORDMARK_BODY}}", body).replace("{{WORDMARK_PUPILS}}", pupils)
    page = page.replace("{{SCREENSHOTS}}", build_screenshots(screenshot_captions()))
    leftover = re.findall(r"\{\{[A-Z_]+\}\}", page)
    if leftover:
        raise SystemExit(f"Unfilled placeholders: {leftover}")
    (PUBLIC / "index.html").write_text(page)
    build_icons()
    total = sum(f.stat().st_size for f in PUBLIC.rglob("*") if f.is_file())
    print(f"Built {PUBLIC.relative_to(ROOT)} ({total / 1024:.0f} KB in {sum(1 for f in PUBLIC.rglob('*') if f.is_file())} files)")


if __name__ == "__main__":
    main()
