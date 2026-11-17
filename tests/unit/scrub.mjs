// Unit checks for the browser module's data hygiene (order keys, e-mail addresses, configured URL
// parameters removed at any depth of an event). Run: node tests/unit/scrub.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../99trace-posthog-for-woocommerce/assets/js/99trace.js', import.meta.url), 'utf8');
const cfg = { token: 'phc_test', consent: 'cookiebot', stripParams: ['key', 'order_key', 'wfocu-key', '_wpnonce', 'token', 'email', 'billing_email', 'phone'] };
const window = {
  w99phwc: cfg,
  location: { hostname: 'shop.example' },
  addEventListener() {},
  setInterval() { return 0; },
  setTimeout() { return 0; },
  clearInterval() {},
  localStorage: { getItem() { return null; }, setItem() {}, length: 0, key() { return null; }, removeItem() {} },
  sessionStorage: { length: 0, key() { return null; }, removeItem() {} },
};
const document = { cookie: '', addEventListener() {}, readyState: 'complete' };
vm.runInNewContext(src, { window, document, RegExp, Object, Array, JSON, String, Date });

let failed = 0;
function check(name, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (!ok) failed++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : `\n     got  ${JSON.stringify(got)}\n     want ${JSON.stringify(want)}`}`);
}
const s = (v) => cfg.scrub(v);

check('thank-you URL: order key removed, other parameters kept',
  s({ $current_url: 'https://shop.example/checkout/order-received/12/?key=wc_order_AbC123&utm_source=ig' }),
  { $current_url: 'https://shop.example/checkout/order-received/12/?utm_source=ig' });
check('only parameter removed, no dangling ?',
  s({ u: 'https://shop.example/tq/?key=wc_order_AbC123' }), { u: 'https://shop.example/tq/' });
check('parameter in the middle',
  s({ u: '/tq/?wfty_source=1&key=wc_order_X9&order_id=12' }), { u: '/tq/?wfty_source=1&order_id=12' });
check('any parameter carrying an order key (download link)',
  s({ u: '/?download_file=5&order=wc_order_Zz1&email=a%40b.example&key=k' }), { u: '/?download_file=5' });
check('encoded order key inside a referrer URL',
  s({ $referrer: 'https://l.example/l.php?u=https%3A%2F%2Fshop%2F%3Fkey%3Dwc_order_Q1w2' }),
  { $referrer: 'https://l.example/l.php?u=https%3A%2F%2Fshop%2F%3Fkey%3Dwc_order_[redacted]' });
check('nested web vitals URL',
  s({ $web_vitals_LCP_event: { name: 'LCP', $current_url: 'https://shop.example/tq/?key=wc_order_A1&x=1' } }),
  { $web_vitals_LCP_event: { name: 'LCP', $current_url: 'https://shop.example/tq/?x=1' } });
check('heatmap data keyed by URL',
  s({ $heatmap_data: { 'https://shop.example/tq/?key=wc_order_A1': [{ x: 1 }] } }),
  { $heatmap_data: { 'https://shop.example/tq/': [{ x: 1 }] } });
check('autocapture element chain href',
  s({ $elements_chain: 'a:href="/my-account/view-order/12/?key=wc_order_A1&p=2"nth-child="1"' }),
  { $elements_chain: 'a:href="/my-account/view-order/12/?p=2"nth-child="1"' });
check('e-mail address in element text',
  s({ $el_text: 'Sent to jane.doe@mail.example on 12 Nov' }), { $el_text: 'Sent to [email] on 12 Nov' });
check('URL-encoded e-mail',
  s({ u: '/x?ref=jane%40mail.example.org' }), { u: '/x?ref=[email]' });
check('person properties ($set_once) and arrays',
  s({ a: ['/p?token=abc&b=1', 7, null], b: { c: { d: '/q?phone=0612&e=2' } } }),
  { a: ['/p?b=1', 7, null], b: { c: { d: '/q?e=2' } } });
check('parameter names are case-insensitive',
  s({ u: '/p?Key=wc_order_A1&KEEP=1' }), { u: '/p?KEEP=1' });
check('untouched values stay identical',
  s({ $current_url: 'https://shop.example/product/x/?utm_source=google&gclid=abc', n: 3, t: true }),
  { $current_url: 'https://shop.example/product/x/?utm_source=google&gclid=abc', n: 3, t: true });
check('a parameter merely ending in a listed name is kept',
  s({ u: '/p?monkey=1&key=2' }), { u: '/p?monkey=1' });

// posthog-js sends its stored first URL with every flag request (before_send never sees it): cleaned in storage.
let saves = 0;
const store = { props: {
  $initial_person_info: { r: 'https://bank.example/return?key=wc_order_Bk1', u: 'https://shop.example/tq/12/?key=wc_order_A1&utm_source=ig&gclid=G1' },
  $stored_person_properties: { plan: 'x' }, distinct_id: 'abc',
}, save() { saves++; } };
cfg.cleanStored({ persistence: store });
check('stored first URL and referrer: order key removed, click ID kept, saved once',
  [store.props.$initial_person_info, saves],
  [{ r: 'https://bank.example/return', u: 'https://shop.example/tq/12/?utm_source=ig&gclid=G1' }, 1]);
cfg.cleanStored({ persistence: store });
check('clean storage is not saved again', saves, 1);

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
