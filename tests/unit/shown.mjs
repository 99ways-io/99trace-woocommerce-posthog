// Unit checks for pages the browser loaded in advance (0.6.0): the shop holds the cart and checkout events of
// a page fetched before the visitor opened it (WordPress's speculative loading), and the browser module
// reports the page once it is shown, whatever the visitor's consent answer so far. posthog-js is a fake (harness.mjs).
// Run: node tests/unit/shown.mjs
import { scenario, check, done } from './harness.mjs';

const URL_ = '/?wc-ajax=w99_phwc_shown';
const TOKEN = '0123456789abcdef0123456789abcdef';
const net = () => {
  const beacons = [];
  const fetches = [];
  return {
    beacons,
    fetches,
    window: {
      navigator: { sendBeacon(url, body) { beacons.push({ url, body }); return true; } },
      fetch(url, opts) { fetches.push({ url, opts }); return Promise.resolve({ json: () => ({}) }); },
    },
  };
};

// 1. An ordinary page (no token): nothing reported.
{
  const n = net();
  scenario({ shownUrl: URL_ }, new Map(), { window: n.window });
  check('ordinary page: nothing reported', [n.beacons.length, n.fetches.length], [0, 0]);
}

// 2. A page loaded in advance, now shown: reported once by beacon, before any consent answer.
{
  const n = net();
  const t = scenario({ shownUrl: URL_, shown: TOKEN }, new Map(), { window: n.window });
  check('shown: reported once to the shop, before the visitor answered the banner', [n.beacons.length, n.beacons[0] && n.beacons[0].url], [1, URL_]);
  t.cfg.reportShown();
  check('shown: never reported twice', n.beacons.length, 1);
}

// 3. No sendBeacon (older browser): a POST with the token, the shop's cookies included.
{
  const n = net();
  delete n.window.navigator;
  scenario({ shownUrl: URL_, shown: TOKEN }, new Map(), { window: n.window });
  const f = n.fetches[0] || { opts: {} };
  check('shown without beacon: POST with the token and the shop\'s cookies', [n.fetches.length, f.opts.method, f.opts.body, f.opts.credentials, f.opts.keepalive], [1, 'POST', 't=' + TOKEN, 'same-origin', true]);
}

// 4. Prerendered: the module runs before the page is shown, so it waits for the switch.
{
  const n = net();
  const t = scenario({ shownUrl: URL_, shown: TOKEN }, new Map(), { window: n.window, document: { prerendering: true } });
  check('prerendered: not reported while hidden', n.beacons.length, 0);
  t.document.prerendering = false;
  t.fire('prerenderingchange');
  check('prerendered: reported once shown', n.beacons.length, 1);
}

// 5. The token printed at the end of the page, after the module started: read when the page has loaded.
{
  const n = net();
  const t = scenario({ shownUrl: URL_ }, new Map(), { window: n.window, document: { readyState: 'loading' } });
  t.cfg.shown = TOKEN;
  t.fire('DOMContentLoaded');
  check('token printed later: reported when the page has loaded', n.beacons.length, 1);
}

done();
