"""Compose 99Trace's icon, WordPress.org banners and GitHub social preview as SVG (rendered to PNG separately).

Palette (99Ways): Graphite #292B2A, Warm White #F4F3EF, Muted #676B65 / #B5B8B1 on dark,
Inquiry blue #3558B8 / #A6BAFF on dark, Outcome green #147D40 / #6BD98D on dark.
The trace (dotted path) is 99Trace's own element: the visitor's journey, ending in the order (green, the
favourable outcome). Green never touches the logo itself.
Usage: python compose.py <geometry.json> <out_dir>
"""
import json
import sys

G = json.load(open(sys.argv[1]))
OUT = sys.argv[2]

GRAPHITE, WARM, MUTED_D, BLUE_D, GREEN_D = '#292B2A', '#F4F3EF', '#B5B8B1', '#A6BAFF', '#6BD98D'
LINE_D = '#3A3D3B'  # a quiet step above Graphite for hairlines and chips


def mark(x, y, width, nines_only=False, ink=WARM, pupil=BLUE_D):
    """The wordmark (or the compact 99) placed at x, y (top of the cap line) at the given visible width."""
    w = 146.0 if nines_only else G['width']
    s = width / w
    body = G['nines'] + ('' if nines_only else G['trace'])
    return ('<g transform="translate(%.3f %.3f) scale(%.5f)"><path fill="%s" d="%s"/><path fill="%s" d="%s"/></g>'
            % (x, y + s, s, ink, body, pupil, G['pupils']))


def trace(x1, x2, y, r, step, end_r, color=MUTED_D, end=GREEN_D):
    """A dotted path from x1 to x2 at height y, ending in the order dot."""
    dots, x = [], x1
    while x < x2 - end_r - step * 0.6:
        dots.append('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>' % (x, y, r, color))
        x += step
    dots.append('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>' % (x2, y, end_r, end))
    return ''.join(dots)


def icon(size=256):
    s = size / 256.0
    return ('<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="99Trace" viewBox="0 0 {0} {0}" width="{0}" height="{0}">'
            '<rect width="{0}" height="{0}" fill="{1}"/>{2}{3}</svg>\n').format(
        size, GRAPHITE,
        mark(52 * s, 46 * s, 152 * s, nines_only=True),
        trace(60 * s, 194 * s, 200 * s, 5.2 * s, 19 * s, 12 * s))


def text(x, y, size, fill, content, family='Inter', weight=400, spacing=0, anchor='start'):
    return ('<text x="%.1f" y="%.1f" font-family="%s" font-size="%.1f" font-weight="%d" fill="%s" letter-spacing="%.2f" text-anchor="%s">%s</text>'
            % (x, y, family, size, weight, fill, spacing, anchor, content))


def journey(x0, y, x1, scale=1.0, alternate=False, label_size=24, end_size=26, sub_size=22):
    """Three stops of a real buyer's journey along the trace, then the order, sent once.

    Labels sit above the line (or alternate above and below when space is short); the order's labels are
    right-aligned under its dot so they never run past the trace's end.
    """
    s = scale
    stops = [('in-app browser', 0.0), ('bank app', 0.34), ('new browser', 0.66)]
    end_r = 16 * s
    out = [trace(x0, x1, y, 4.4 * s, 17.5 * s, end_r)]
    for i, (label, f) in enumerate(stops):
        cx = x0 + (x1 - x0) * f
        out.append('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="%s" stroke-width="%.1f"/>' % (cx, y, 11.5 * s, GRAPHITE, BLUE_D, 3.4 * s))
        below = alternate and i % 2 == 1
        ly = y + (46 * s if below else -30 * s)
        out.append(text(cx, ly, label_size * s, MUTED_D, label, family='IBM Plex Mono', weight=500, anchor='middle'))
    out.append(text(x1 + end_r, y + 50 * s, end_size * s, GREEN_D, 'Order Completed', family='IBM Plex Mono', weight=600, anchor='end'))
    out.append(text(x1 + end_r, y + 80 * s, sub_size * s, MUTED_D, 'sent once', family='IBM Plex Mono', weight=400, anchor='end'))
    return ''.join(out)


def journey_vertical(x, y0, y1, scale=1.0):
    """The same journey running down the banner's right side: labels beside each stop, never colliding."""
    s = scale
    end_r = 15 * s
    out, y, step = [], y0, 17 * s
    while y < y1 - end_r - step * 0.6:
        out.append('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>' % (x, y, 4.2 * s, MUTED_D))
        y += step
    out.append('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>' % (x, y1, end_r, GREEN_D))
    for label, f in (('in-app browser', 0.0), ('bank app', 1 / 3.0), ('new browser', 2 / 3.0)):
        cy = y0 + (y1 - y0) * f
        out.append('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="%s" stroke-width="%.1f"/>' % (x, cy, 11 * s, GRAPHITE, BLUE_D, 3.4 * s))
        out.append(text(x + 34 * s, cy + 9 * s, 26 * s, MUTED_D, label, family='IBM Plex Mono', weight=500))
    out.append(text(x + 34 * s, y1 + 9 * s, 28 * s, GREEN_D, 'Order Completed', family='IBM Plex Mono', weight=600))
    out.append(text(x + 34 * s, y1 + 44 * s, 23 * s, MUTED_D, 'sent once, same visitor', family='IBM Plex Mono', weight=400))
    return ''.join(out)


def banner(w=1544, h=500):
    s = w / 1544.0
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">'
            '<rect width="{w}" height="{h}" fill="{bg}"/>'
            '{mark}{t1}{t2}{j}</svg>\n').format(
        w=w, h=h, bg=GRAPHITE,
        mark=mark(96 * s, 112 * s, 500 * s),
        t1=text(98 * s, 300 * s, 41 * s, WARM, 'Every WooCommerce order in PostHog.', weight=600, spacing=-0.4 * s),
        t2=text(98 * s, 352 * s, 33 * s, MUTED_D, 'Exactly once, on the visitor who placed it.', weight=400, spacing=-0.2 * s),
        j=journey_vertical(1080 * s, 104 * s, 352 * s, scale=s))


def social(w=1280, h=640):
    s = w / 1280.0
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">'
            '<rect width="{w}" height="{h}" fill="{bg}"/>'
            '{mark}{t1}{t2}{j}{foot}</svg>\n').format(
        w=w, h=h, bg=GRAPHITE,
        mark=mark(96 * s, 104 * s, 560 * s),
        t1=text(98 * s, 300 * s, 46 * s, WARM, 'Every WooCommerce order in PostHog.', weight=600, spacing=-0.5 * s),
        t2=text(98 * s, 356 * s, 34 * s, MUTED_D, 'Exactly once, on the visitor who placed it.', weight=400, spacing=-0.2 * s),
        j=journey(196 * s, 466 * s, 1166 * s, scale=s, label_size=23, end_size=25, sub_size=21),
        foot=text(98 * s, 604 * s, 20 * s, '#8C908A', 'Open source  ·  GPL-2.0-or-later  ·  by 99Ways', family='IBM Plex Mono', weight=400))


open(OUT + '/icon.svg', 'w').write(icon())
open(OUT + '/banner.svg', 'w').write(banner())
open(OUT + '/social-preview.svg', 'w').write(social())
print('ok')
