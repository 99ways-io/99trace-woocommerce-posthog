// Unit checks for the one visitor ID the browser module shares with the shop's server (0.5.0): posthog-js
// starts with the shop's ID or a new one written for the server, the cookie follows posthog-js's ID, an ID
// the server created while posthog-js had another is merged once, and a thank-you page links an order placed
// before consent. posthog-js is a fake (harness.mjs).
// Run: node tests/unit/visitor.mjs
import { scenario, check, done } from './harness.mjs';

const VID = 'ph_wc_vid';
const V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const K = '01a1e202-c74d-7c67-b5e8-665a97760e8b'; // an ID the server created
const every = { consent: 'none', earlyStart: false, beforeConsent: 'off', visitorCookie: VID, cookiePath: '/', cookieDomain: '' };
const history = 'ph_phc_test_posthog=' + encodeURIComponent(JSON.stringify({ distinct_id: 'earlier-visitor' }));

// 1. A new visitor: the module creates the ID before posthog-js has loaded, writes it for the server, starts posthog-js with it.
{
  const t = scenario(every);
  const boot = t.config().bootstrap;
  check('new visitor: posthog-js starts with a new ID in its own form (v7)', !!boot && V7.test(boot.distinctID) && boot.isIdentifiedID === false, true);
  check('new visitor: the ID is in the shop cookie before posthog-js has loaded', t.cookie(VID), boot.distinctID);
  const ageMs = Date.now() - parseInt(boot.distinctID.replace(/-/g, '').slice(0, 12), 16);
  check('new visitor: the ID carries the current time', ageMs >= 0 && ageMs < 5000, true);
  const p = t.load();
  check('new visitor: posthog-js uses that ID; nothing merged', [p.get_distinct_id(), t.aliases.length], [boot.distinctID, 0]);
  check('new visitor: cookie written by the cookie path and lasts a year, not secure-less on https', /path=\/; max-age=31536000; samesite=lax; secure$/.test(t.cookies.writes[0]), true);
}

// 2. The server acted first (an "add to cart" link as the landing page): posthog-js adopts its ID.
{
  const t = scenario(every, new Map(), { cookie: `${VID}=${K}.s` });
  check('server first: posthog-js starts with the server\'s ID', t.config().bootstrap.distinctID, K);
  check('server first: the server\'s mark is dropped once adopted', t.cookie(VID), K);
  t.load();
  check('server first: nothing to merge', t.aliases.length, 0);
}

// 3. posthog-js already had an ID while the server created another (a request that carried neither cookie).
{
  const t = scenario(every, new Map(), { cookie: `${history}; ${VID}=${K}.s` });
  check('both had an ID: posthog-js keeps its own', t.config().bootstrap, undefined);
  const p = t.load();
  check('both had an ID: the server\'s is merged into posthog-js\'s once (alias)', t.aliases, [{ alias: K, distinct_id: 'earlier-visitor' }]);
  check('both had an ID: the cookie follows posthog-js from now on', t.cookie(VID), 'earlier-visitor');
  t.answer(true); // a later consent event changes nothing
  check('both had an ID: merged only once', t.aliases.length, 1);
  void p;
}

// 4. A plain cookie (written by the browser earlier) that differs from posthog-js: replaced, never merged.
{
  const t = scenario(every, new Map(), { cookie: `${history}; ${VID}=${K}` });
  t.load();
  check('browser-written cookie out of date: replaced by posthog-js\'s ID, no merge', [t.cookie(VID), t.aliases.length], ['earlier-visitor', 0]);
}

// 5. posthog-js lost its storage but the shop cookie survived: the visitor keeps their ID.
{
  const t = scenario(every, new Map(), { cookie: `${VID}=${K}` });
  check('posthog-js storage gone, shop cookie kept: same visitor ID', t.config().bootstrap.distinctID, K);
}

// 5b. The cookie is written on every page (Safari keeps a script's cookie 7 days from its last write).
{
  const t = scenario(every, new Map(), { cookie: `${history}; ${VID}=earlier-visitor` });
  t.load();
  check('every page: the visitor cookie is written again, also when unchanged', t.cookies.writes.filter((w) => w.startsWith(VID + '=earlier-visitor')).length, 1);
}

// 6. Off in the settings: 0.4.0 behaviour.
{
  const t = scenario({ ...every, visitorCookie: '' }, new Map(), { cookie: `${VID}=${K}.s` });
  t.load();
  check('setting off: no bootstrap, no cookie written, no merge', [t.config().bootstrap, t.cookies.writes.length, t.aliases.length], [undefined, 0, 0]);
}

// 7. Consent modes: nothing is written before the answer; on Accept the cookie follows posthog-js.
{
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' });
  t.load();
  check('switched off before the answer: no ID written', t.cookie(VID), null);
  t.answer(true);
  check('on Accept: the cookie holds posthog-js\'s ID', t.cookie(VID), 'visitor-1');
}
{
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: true, beforeConsent: 'hold' });
  const p = t.load();
  p.capture('$pageview', {});
  check('recording before the answer: no ID written', t.cookie(VID), null);
  t.answer(true);
  check('recording, then Accept: the cookie holds the visitor\'s ID', t.cookie(VID), 'visitor-1');
}
{
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: false, beforeConsent: 'off' }, new Map(), { cookie: `${history}; ${VID}=${K}` });
  t.answer(false);
  check('refusal: the shop\'s visitor cookie is deleted with PostHog\'s', t.cookie(VID), null);
}
{
  // A site in a subdirectory: WordPress's cookie path is /shop/, so a refusal deletes there too.
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: false, beforeConsent: 'off', cookiePath: '/shop/' }, new Map(), { cookie: `${history}; ${VID}=${K}; ph_wc_logout=1` });
  t.answer(false);
  const deletes = t.cookies.writes.filter((w) => w.startsWith(VID + '=;')).map((w) => /path=([^;]+)/.exec(w)[1]);
  check('refusal, site in a subdirectory: the visitor cookie deleted under /shop/ and /', [deletes.includes('/shop/'), deletes.includes('/')], [true, true]);
}
{
  // The WP Consent API's cookie prefix is filterable (wp_consent_cookie_prefix): read it from the API.
  const t = scenario({ ...every, consent: 'wp_consent_api', earlyStart: false, beforeConsent: 'off' }, new Map(), { cookie: `${history}; ${VID}=${K}; myshop_statistics=deny`, window: { wp_has_consent: () => false, consent_api: { cookie_prefix: 'myshop' } } });
  check('WP Consent API with its own cookie prefix: a stored refusal is read, the visitor cookie deleted', t.cookie(VID), null);
}
{
  const t = scenario({ ...every, consent: 'posthog_opt_in' });
  t.load();
  check('the site\'s own opt-in: no ID written before it opts the visitor in', [t.config().bootstrap, t.cookie(VID)], [undefined, null]);
}

// 8. Accounts: a signed-in customer keeps the anonymous ID (merged into the account); logout starts afresh.
{
  const t = scenario({ ...every, identifyCustomers: true, customerCookie: 'ph_wc_customer' }, new Map(), { cookie: `${history}; ${VID}=${K}.s; ph_wc_customer=wc:shop:7` });
  t.load();
  check('signed in: the server\'s ID is merged into the account', t.aliases, [{ alias: K, distinct_id: 'wc:shop:7' }]);
  check('signed in: the cookie keeps the anonymous ID, without the server\'s mark', t.cookie(VID), K);
}
{
  const t = scenario({ ...every, customerPrefix: 'wc:shop:' }, new Map(), { cookie: `${history}; ${VID}=${K}; ph_wc_logout=1` });
  t.load();
  check('after logout: posthog-js resets and the cookie follows the new visitor', t.cookie(VID), 'after-reset');
}

// 9. Thank-you page in a fresh browser (bank app): it continues the order's ID, which the cookie then holds.
{
  const t = scenario({ ...every, linkReturn: true, orderLink: { order_id: '5', linkable: true, distinct_id: K } });
  check('thank-you page, fresh browser: started with the order\'s ID', t.config().bootstrap.distinctID, K);
  t.load();
  check('thank-you page, fresh browser: the cookie holds the order\'s ID', t.cookie(VID), K);
}

// 10. An order placed before the visitor could be recorded: linked once they accept on its thank-you page.
function withFetch() {
  const posts = [];
  const fetch = (url, opts) => { posts.push({ url, body: new URLSearchParams(opts.body), credentials: opts.credentials }); return Promise.resolve({ json: () => Promise.resolve({ linked: true }) }); };
  return { posts, window: { fetch, location: { hostname: 'shop.example', href: 'https://shop.example/', protocol: 'https:', search: '?key=wc_order_AbC123&order-received=5' } } };
}
{
  const f = withFetch();
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch', linkReturn: true, linkUrl: '/?wc-ajax=w99_phwc_link', orderLink: { order_id: '5', linkable: false, claimable: true, reason: 'order_not_linked' } }, new Map(), { window: f.window });
  t.load();
  check('placed before consent: nothing sent to the shop before the answer', f.posts.length, 0);
  t.answer(true);
  const post = f.posts[0];
  check('placed before consent: on Accept the shop is asked to link the order, with its key', post && [post.url, post.body.get('order_id'), post.body.get('key'), post.credentials], ['/?wc-ajax=w99_phwc_link', '5', 'wc_order_AbC123', 'same-origin']);
  check('placed before consent: under posthog-js\'s ID', post && JSON.parse(post.body.get('identity')).distinct_id, 'visitor-1');
  t.answer(true);
  check('placed before consent: asked once', f.posts.length, 1);
}
{
  // "Wait" before the answer: posthog-js loads only after the Accept, and its loaded callback runs while window.posthog is still the stub.
  const f = withFetch();
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: false, beforeConsent: 'off', linkReturn: true, linkUrl: '/?wc-ajax=w99_phwc_link', orderLink: { order_id: '5', linkable: false, claimable: true, reason: 'order_not_linked' } }, new Map(), { window: f.window });
  check('placed before consent, "Wait": posthog-js not loaded before the answer', t.config(), undefined);
  t.answer(true);
  const p = t.load();
  const post = f.posts[0];
  check('placed before consent, "Wait": the link is asked from posthog-js\'s loaded callback, under its new ID', post && [post.body.get('order_id'), JSON.parse(post.body.get('identity')).distinct_id], ['5', p.get_distinct_id()]);
  check('placed before consent, "Wait": from then on the shop cookie holds the visitor', t.cookie(VID), p.get_distinct_id());
}
{
  const f = withFetch();
  const t = scenario({ ...every, linkUrl: '/?wc-ajax=w99_phwc_link', orderLink: { order_id: '5', linkable: true, distinct_id: K } }, new Map(), { window: f.window, cookie: history });
  t.load();
  check('an order already linked: nothing asked', f.posts.length, 0);
}

done();
