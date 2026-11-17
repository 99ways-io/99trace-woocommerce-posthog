"""Build the 99Trace wordmark from the 99Ways master geometry and IBM Plex Mono SemiBold outlines.

Construction follows the 99Ways logo specification: cap height 100 units, baseline at 100, the two custom 9s
(146 x 101 units) from the compact master, a 10-unit gap from the second 9 to the first letter, and 0-unit gaps
between the extreme visible bounds of consecutive letters. "Ways" is rebuilt first and compared with the master
to prove the method, then "Trace" is set the same way.
Usage: python wordmark.py <font.ttf> <out_dir>
"""
import json
import re
import sys

from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

FONT = TTFont(sys.argv[1])
OUT = sys.argv[2]
GLYPHS = FONT.getGlyphSet()
CMAP = FONT.getBestCmap()
SCALE = 100.0 / FONT['OS/2'].sCapHeight

# The compact master's two 9s (bodies) and pupils, verbatim (viewBox 0 -1 146 101).
NINES = ("M72.0 35.0C72.0 42.84147644042969 69.492919921875 50.09739303588867 65.23658752441406 56.00990676879883L34.43439483642578 100.0H12.434394836425781L32.83640670776367 70.86290740966797C14.435773849487305 69.26087188720703 0.0 53.81626510620117 0.0 35.0C0.0 15.117749214172363 16.117748260498047 -1.0 36.0 -1.0C55.88225173950195 -1.0 72.0 15.117749214172363 72.0 35.0ZM146.0 35.0C146.0 42.84147644042969 143.492919921875 50.09739303588867 139.23658752441406 56.00990676879883L108.43439483642578 100.0H86.43439483642578L106.83641052246094 70.86290740966797C88.43577575683594 69.26087188720703 74.0 53.81626510620117 74.0 35.0C74.0 15.117749214172363 90.11775207519531 -1.0 110.0 -1.0C129.8822479248047 -1.0 146.0 15.117749214172363 146.0 35.0ZM130.0 35.0C130.0 23.95430564880371 121.04570007324219 15.0 110.0 15.0C98.95430755615234 15.0 90.0 23.95430564880371 90.0 35.0C90.0 46.04569625854492 98.95430755615234 55.0 110.0 55.0C121.04570007324219 55.0 130.0 46.04569625854492 130.0 35.0ZM56.0 35.0C56.0 23.95430564880371 47.04569625854492 15.0 36.0 15.0C24.95430564880371 15.0 16.0 23.95430564880371 16.0 35.0C16.0 46.04569625854492 24.95430564880371 55.0 36.0 55.0C47.04569625854492 55.0 56.0 46.04569625854492 56.0 35.0Z")
PUPILS = ("M131.6883087158203 40.916656494140625C131.6883087158203 45.8872184753418 127.65887451171875 49.916656494140625 122.68830871582031 49.916656494140625C117.71774291992188 49.916656494140625 113.68830871582031 45.8872184753418 113.68830871582031 40.916656494140625C113.68830871582031 35.94609451293945 117.71774291992188 31.916656494140625 122.68830871582031 31.916656494140625C127.65887451171875 31.916656494140625 131.6883087158203 35.94609451293945 131.6883087158203 40.916656494140625ZM57.68830871582031 40.916656494140625C57.68830871582031 45.8872184753418 53.658870697021484 49.916656494140625 48.68830871582031 49.916656494140625C43.71774673461914 49.916656494140625 39.68830871582031 45.8872184753418 39.68830871582031 40.916656494140625C39.68830871582031 35.94609451293945 43.71774673461914 31.916656494140625 48.68830871582031 31.916656494140625C53.658870697021484 31.916656494140625 57.68830871582031 35.94609451293945 57.68830871582031 40.916656494140625Z")


def letters(text, start=156.0):
    """Outline `text` with 0-unit gaps between visible bounds, starting at `start`. Returns (path, right, bottom)."""
    cursor, parts, bottom = start, [], 100.0
    for ch in text:
        name = CMAP[ord(ch)]
        bp = BoundsPen(GLYPHS)
        GLYPHS[name].draw(bp)
        x_min, y_min, x_max, y_max = bp.bounds
        pen = SVGPathPen(GLYPHS, ntos=lambda v: ('%.4f' % v).rstrip('0').rstrip('.'))
        # Font units (y up) to logo units (y down, baseline at 100), visible left edge at the cursor.
        GLYPHS[name].draw(TransformPen(pen, (SCALE, 0, 0, -SCALE, cursor - x_min * SCALE, 100.0)))
        parts.append(pen.getCommands())
        cursor += (x_max - x_min) * SCALE
        bottom = max(bottom, 100.0 - y_min * SCALE)
    return ''.join(parts), cursor, bottom


ways, ways_right, ways_bottom = letters('Ways')
print('rebuilt Ways: width %.4f (master 455.7135), bottom %.4f (master 128.6533)' % (ways_right, ways_bottom))
assert abs(ways_right - 455.7135) < 0.05 and abs(ways_bottom - 128.6533) < 0.05, 'method does not reproduce the master'

trace, right, bottom = letters('Trace')
width, height = right, bottom + 1.0  # viewBox starts at -1 (the bowls overshoot the cap line by 1 unit)
print('99Trace: width %.4f, height %.4f' % (width, height))

TREATMENTS = {
    'light-background': ('#292B2A', '#3558B8'),
    'dark-background': ('#F4F3EF', '#A6BAFF'),
    'one-color-black': ('#000000', '#000000'),
    'one-color-white': ('#FFFFFF', '#FFFFFF'),
}
for name, (ink, pupil) in TREATMENTS.items():
    svg = ('<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="99Trace" viewBox="0 -1 %.4f %.4f">'
           '<path fill="%s" d="%s%s"/><path fill="%s" d="%s"/></svg>\n') % (width, height, ink, NINES, trace, pupil, PUPILS)
    open('%s/99trace-wordmark-%s.svg' % (OUT, name), 'w').write(svg)

json.dump({'nines': NINES, 'pupils': PUPILS, 'trace': trace, 'width': width, 'height': height},
          open('%s/wordmark-geometry.json' % OUT, 'w'))
