// Render SVG files to PNG with resvg, using only the bundled fonts (never system fonts).
// Usage: node render.js <fonts_dir> <in.svg> <out.png> <width> [background]
const fs = require('fs');
const path = require('path');
const { Resvg } = require('@resvg/resvg-js');

const [fontsDir, input, output, width, background] = process.argv.slice(2);
const fontFiles = fs.readdirSync(fontsDir).filter((f) => f.endsWith('.ttf')).map((f) => path.join(fontsDir, f));
const resvg = new Resvg(fs.readFileSync(input, 'utf8'), {
  fitTo: { mode: 'width', value: Number(width) },
  background: background || undefined,
  font: { fontFiles, loadSystemFonts: false, defaultFontFamily: 'Inter' },
  shapeRendering: 2,
  textRendering: 1,
});
const png = resvg.render().asPng();
fs.writeFileSync(output, png);
console.log(`${path.basename(output)} ${png.length} bytes`);
