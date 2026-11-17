// Unit checks for the consent modes of the browser module: "Record the visit" (hold), the consent
// switch and "Track every visitor". posthog-js is a fake that runs before_send like the real one.
// Run: node tests/unit/hold.mjs
import { scenario, check, done } from './harness.mjs';

// 1. Record the visit, then accept: everything from before is sent, at its own time, under the visitor.
{
  const t = scenario({});
  const cfg0 = t.config();
  check('hold: posthog-js starts at page load on page memory, no replay, no surveys', [cfg0.persistence, cfg0.disable_session_recording, cfg0.disable_surveys, cfg0.opt_out_capturing_by_default], ['memory', true, true, undefined]);
  const p = t.load();
  const early = new Date('2026-11-13T10:00:00Z');
  p.capture('$pageview', { $current_url: 'https://shop.example/p/?key=wc_order_Abc1' }, { timestamp: early });
  p.capture('$autocapture', { $el_text: 'Add to cart' });
  check('hold: nothing sent before the answer', t.sent.length, 0);
  check('hold: no identity for the checkout before the answer', t.cfg.identity(), null);
  t.answer(true);
  check('accept: both events sent', t.sent.map((e) => e.event), ['$pageview', '$autocapture']);
  check('accept: original time kept', new Date(t.sent[0].timestamp).toISOString(), early.toISOString());
  check('accept: still scrubbed', t.sent[0].properties.$current_url, 'https://shop.example/p/');
  check('accept: under the visitor\'s identity at sending', t.sent[0].properties.distinct_id, 'visitor-1');
  check('accept: the browser keeps the visitor from now on', t.config().persistence, 'localStorage+cookie');
  p.capture('$pageleave', {});
  check('after accept: events go straight to PostHog', t.sent.length, 3);
}

// 2. Record the visit, then refuse: the recording is dropped and nothing is ever sent.
{
  const t = scenario({});
  const p = t.load();
  p.capture('$pageview', {});
  t.answer(false);
  p.capture('$autocapture', {});
  check('refuse: nothing sent, before or after', t.sent.length, 0);
  check('refuse: no opt-out mark stored', !!p.optedOut, false);
  t.answer(true);
  p.capture('$pageview', {});
  check('yes after a no: capture starts from then on, the dropped recording stays dropped', t.sent.map((e) => e.event), ['$pageview']);
}

// 3. Across pages: page 1's recording waits in session storage and is sent with page 2's on consent.
{
  const storage = new Map();
  const a = scenario({ holdAcrossPages: true }, storage);
  a.load().capture('$pageview', { $current_url: 'https://shop.example/a/' });
  check('across pages: page 1 kept in session storage', JSON.parse(storage.get('ph_wc_held') || '[]').length, 1);
  const b = scenario({ holdAcrossPages: true }, storage);
  b.load().capture('$pageview', { $current_url: 'https://shop.example/b/' });
  b.answer(true);
  check('across pages: both pages sent on consent', b.sent.map((e) => e.properties.$current_url), ['https://shop.example/a/', 'https://shop.example/b/']);
  check('across pages: storage cleared after sending', storage.has('ph_wc_held'), false);
}
{
  const storage = new Map([['ph_wc_held', '[{"e":"$pageview","p":{},"t":"2026-11-13T10:00:00.000Z"}]']]);
  const t = scenario({ holdAcrossPages: false }, storage);
  check('one page only: a leftover recording is deleted, never sent', storage.has('ph_wc_held'), false);
}

// 4. The consent switch is unchanged: switched off until consent, nothing recorded.
{
  const t = scenario({ beforeConsent: 'switch' });
  const c = t.config();
  check('switch: opted out by default, normal storage', [c.opt_out_capturing_by_default, c.persistence], [true, undefined]);
}

// 5. Track every visitor: starts at once, and Cookiebot never blocks posthog-js.
{
  const t = scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off' });
  const c = t.config();
  check('track every visitor: posthog-js starts without waiting', !!c && c.opt_out_capturing_by_default, undefined);
  check('track every visitor: loader marked for Cookiebot to ignore', t.scripts[0].attrs['data-cookieconsent'], 'ignore');
  const p = t.load();
  p.capture('$pageview', {});
  check('track every visitor: events sent at once', t.sent.length, 1);
}

// 5b. Review fixes: refusal then consent stores the visitor; replays skip the client rate limit;
// WP Consent API "not answered" is no refusal; one visitor ID across recorded pages; JSON cannot undo an Events switch.
{
  const t = scenario({});
  const p = t.load();
  t.answer(false);
  t.answer(true);
  check('refused, then accepted: the browser stores the visitor from then on', t.config().persistence, 'localStorage+cookie');
}
{
  const t = scenario({});
  const p = t.load();
  p.capture('$pageview', {});
  t.answer(true);
  check('replayed events are not cut by posthog-js\'s client rate limit', t.calls.filter((c) => c.opts && c.opts.skip_client_rate_limiting).length, 1);
}
{
  let consent = false;
  const t = scenario({ consent: 'wp_consent_api' }, new Map(), { window: { wp_has_consent: () => consent } });
  const p = t.load();
  p.capture('$pageview', {});
  check('WP Consent API, not answered yet: still recording, nothing sent', [t.sent.length, t.config().persistence], [0, 'memory']);
  consent = true;
  t.fire('wp_listen_for_consent_change');
  check('WP Consent API, then accepted: the recording is sent', t.sent.map((e) => e.event), ['$pageview']);
}
{
  const t = scenario({ consent: 'wp_consent_api' }, new Map(), { window: { wp_has_consent: () => false }, cookie: 'wp_consent_statistics=deny' });
  const p = t.load();
  p.capture('$pageview', {});
  t.fire('wp_listen_for_consent_change');
  check('WP Consent API, refusal on record: nothing recorded or sent', t.sent.length, 0);
}
{
  const storage = new Map();
  const a = scenario({ holdAcrossPages: true }, storage);
  const b = scenario({ holdAcrossPages: true }, storage);
  const idA = a.config().bootstrap && a.config().bootstrap.distinctID;
  check('across pages: every recorded page uses the same visitor ID', !!idA && idA === (b.config().bootstrap && b.config().bootstrap.distinctID), true);
  b.load();
  b.answer(true);
  check('across pages: the shared ID is removed from session storage after consent', storage.has('ph_wc_held_id'), false);
  const one = scenario({});
  check('one page only: no ID kept in the browser', [one.config().bootstrap, one.storage.has('ph_wc_held_id')], [undefined, false]);
}
{
  const cookie = 'ph_phc_test_posthog=' + encodeURIComponent(JSON.stringify({ distinct_id: 'earlier-visitor' }));
  const t = scenario({}, new Map(), { cookie });
  check('a visitor stored at an earlier consent keeps that ID while recording', t.config().bootstrap.distinctID, 'earlier-visitor');
}
{
  const t = scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off', pageview: false, autocapture: false, posthogConfig: { capture_pageview: true, autocapture: true } });
  check('an event switched off in the Events settings stays off, whatever the further options say', [t.config().capture_pageview, t.config().autocapture], [false, false]);
}

// 5c. Mode Test: posthog-js runs, but no event leaves the browser.
{
  const t = scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off', testMode: true, debug: false });
  const p = t.load();
  p.capture('$pageview', {});
  p.capture('$autocapture', {});
  check('test mode: posthog-js runs but sends nothing', [!!t.config(), t.sent.length], [true, 0]);
}

// 6. Events settings in the browser: a renamed event, page views off, order pages always protected.
{
  const t = scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off', eventNames: { 'Product Viewed': 'Viewed Product' }, events: { productViewed: true }, page: { type: 'product', product: { product_id: '7' } }, pageview: false, sensitive: true, posthogConfig: { disable_session_recording: false, capture_heatmaps: true } });
  const c = t.config();
  check('page views off: posthog-js told not to capture them', c.capture_pageview, false);
  check('order and account pages: no replay or heatmaps, whatever the shop\'s own options say', [c.disable_session_recording, c.capture_heatmaps, c.mask_all_text], [true, false, true]);
  t.load();
  check('a renamed browser event is sent under its new name', t.sent.map((e) => e.event), ['Viewed Product']);
}
{
  const t = scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off', events: { productViewed: true }, page: { type: 'product', product: {} } });
  t.load();
  check('without a custom name the standard one is used', t.sent.map((e) => e.event), ['Product Viewed']);
}

// 10. Switched off, a returning visitor asked again (answer expired): the stored visitor is kept in page memory,
// the cookie is not deleted, and Accept stores the same visitor again.
{
  const stored = 'ph_phc_test_posthog=' + encodeURIComponent(JSON.stringify({ distinct_id: 'old-visitor', $user_state: 'identified', $device_id: 'dev-1' }));
  {
    const t = scenario({ earlyStart: true, beforeConsent: 'switch' }, new Map(), { cookie: stored });
    const c = t.config();
    check('switched off, stored visitor: kept in memory under its ID, nothing deleted', [c.persistence, c.bootstrap, c.opt_out_persistence_by_default, c.opt_out_capturing_by_default], ['memory', { distinctID: 'old-visitor', isIdentifiedID: true }, undefined, true]);
    t.load();
    t.answer(true);
    check('switched off, stored visitor: Accept stores the same visitor again', c.persistence, 'localStorage+cookie');
  }
  {
    const t = scenario({ earlyStart: true, beforeConsent: 'switch' }, new Map(), { cookie: '' });
    const c = t.config();
    check('switched off, new visitor: unchanged (store nothing until the answer)', [c.persistence, c.bootstrap, c.opt_out_persistence_by_default], [undefined, undefined, true]);
  }
  {
    const t = scenario({ earlyStart: true, beforeConsent: 'hold' }, new Map(), { cookie: stored });
    check('record the visit, stored identified visitor: kept as identified', t.config().bootstrap, { distinctID: 'old-visitor', isIdentifiedID: true });
  }
}

// 11. Dead clicks follow the PostHog project's setting unless the shop overrides them.
{
  const base = { consent: 'none', earlyStart: false, beforeConsent: 'off', autocapture: true };
  check('dead clicks: PostHog\'s setting leaves posthog-js\'s option unset', 'capture_dead_clicks' in scenario({ ...base, deadClicks: null }).config(), false);
  check('dead clicks: Always on / Always off reach posthog-js', [scenario({ ...base, deadClicks: true }).config().capture_dead_clicks, scenario({ ...base, deadClicks: false }).config().capture_dead_clicks], [true, false]);
  check('dead clicks: Always off wins over the further options', scenario({ ...base, deadClicks: false, posthogConfig: { capture_dead_clicks: true } }).config().capture_dead_clicks, false);
}

// 12. Customer details on the person: checkout fields as person properties once there is consent;
// kept through the scrubbing in $set only; e-mail addresses stay out of every other event.
{
  const fields = { billing_email: ' Buyer@Example.com ', billing_first_name: 'Ana', billing_last_name: 'Lopez', billing_phone: '+33 6 12 34 56 78' };
  const querySelector = (sel) => { const m = /\[name="([^"]+)"\]/.exec(sel); return m && fields[m[1]] !== undefined ? { value: fields[m[1]], closest: () => ({}) } : null; };
  const detailScenario = (cfg) => scenario({ consent: 'none', earlyStart: false, beforeConsent: 'off', personDetails: true, personProfiles: 'always', ...cfg }, new Map(), { querySelector });
  {
    const t = detailScenario({});
    const p = t.load();
    p.capture('$autocapture', { $el_text: 'mail me at buyer@example.com' });
    check('details: checkout fields set on the person once', t.personSets, [{ email: 'buyer@example.com', first_name: 'Ana', last_name: 'Lopez', phone: '+33 6 12 34 56 78', name: 'Ana Lopez' }]);
    const ev = t.sent.find((e) => e.event === '$set');
    check('details: kept in $set through the scrubbing', ev && ev.properties.$set.email, 'buyer@example.com');
    const ac = t.sent.find((e) => e.event === '$autocapture');
    check('details: e-mail addresses still removed from every other event', ac && ac.properties.$el_text, 'mail me at [email]');
  }
  {
    const t = detailScenario({ personDetails: false });
    t.load();
    check('details: setting off, nothing set', t.personSets.length, 0);
  }
  {
    const t = detailScenario({ consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' });
    t.load();
    check('details: nothing before the visitor answers', t.personSets.length, 0);
    t.answer(true);
    check('details: set once the visitor accepts', t.personSets.length, 1);
  }
  {
    const t = detailScenario({ personProfiles: 'identified_only' });
    t.load();
    check('details: persons only for signed-in customers: an anonymous visitor gets no person', t.personSets.length, 0);
  }
}

done();
