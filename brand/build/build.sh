#!/bin/sh
# Rebuild every 99Trace brand asset from the 99Ways logo masters and the brand fonts.
# Needs: Python 3 with fonttools (pip install fonttools brotli), Node 20+, and
# npm install fetches Inter and Plex Mono for text; font/ holds the logo's IBM Plex Mono SemiBold.
set -eu
cd "$(dirname "$0")"
npm install --silent
mkdir -p fonts
cp font/IBMPlexMono-SemiBold.ttf fonts/   # the exact outlines the 99Ways logo uses (OFL, see font/OFL.txt)
for f in inter-latin-400-normal inter-latin-600-normal ibm-plex-mono-latin-400-normal ibm-plex-mono-latin-500-normal ibm-plex-mono-latin-600-normal; do
  python3 -c "import glob; from fontTools.ttLib import TTFont; f = TTFont(glob.glob('node_modules/@fontsource/*/files/$f.woff2')[0]); f.flavor = None; f.save('fonts/$f.ttf')"
done
python3 wordmark.py fonts/IBMPlexMono-SemiBold.ttf ../logo
mv ../logo/wordmark-geometry.json .
mkdir -p out
python3 compose.py wordmark-geometry.json out
node render.js fonts out/icon.svg ../../.wordpress-org/icon-128x128.png 128
node render.js fonts out/icon.svg ../../.wordpress-org/icon-256x256.png 256
cp out/icon.svg ../../.wordpress-org/icon.svg
cp out/icon.svg ../icon/99trace-icon.svg
node render.js fonts out/icon.svg ../icon/99trace-icon-512.png 512
node render.js fonts out/icon.svg ../icon/99trace-icon-1024.png 1024
node render.js fonts out/banner.svg ../../.wordpress-org/banner-772x250.png 772
node render.js fonts out/banner.svg ../../.wordpress-org/banner-1544x500.png 1544
cp out/banner.svg ../social/99trace-banner.svg
cp out/social-preview.svg ../social/99trace-social-preview.svg
node render.js fonts out/social-preview.svg ../social/99trace-social-preview-1280x640.png 1280
echo "Brand assets rebuilt."
