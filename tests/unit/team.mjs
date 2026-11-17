// Corner cases of the team's changes on top of 0.5.0 (16 Nov 2026): customer details on the person,
// "Switched off" keeping a returning visitor, the renamed storage keys.
// Run: node tests/unit/team.mjs
import { scenario, check, done } from './harness.mjs';

const stored = (id, extra = {}) => 'ph_phc_test_posthog=' + encodeURIComponent(JSON.stringify({ distinct_id: id, $device_id: 'dev-' + id, ...extra }));
const fieldsOf = (fields) => (sel) => {
  const m = /\[name="([^"]+)"\]/.exec(sel);
  return m && fields[m[1]] !== undefined ? { value: fields[m[1]], closest: () => ({}) } : null;
};
const every = { consent: 'none', earlyStart: false, beforeConsent: 'off', personDetails: true, personProfiles: 'always' };
const setOn = (t) => t.sent.filter((e) => e.event === '$set').map((e) => e.properties.$set);

// 1. Customer details: an address that is not an e-mail is not sent; the other fields are.
{
  const t = scenario(every, new Map(), { querySelector: fieldsOf({ billing_email: 'not-an-email', billing_first_name: 'Ana' }) });
  t.load();
  check('details: an invalid e-mail is left out, the name still set', t.personSets, [{ first_name: 'Ana', name: 'Ana' }]);
}

// 2. A field changed later: only the new value is set again; an unchanged one is not repeated.
{
  const fields = { billing_email: 'first@example.com', billing_first_name: 'Ana' };
  const t = scenario(every, new Map(), { querySelector: fieldsOf(fields) });
  t.load();
  fields.billing_email = 'second@example.com';
  t.fire('change', { target: { name: 'billing_email', closest: () => ({}) } });
  t.fire('change', { target: { name: 'billing_first_name', closest: () => ({}) } });
  t.fire('change', { target: { name: 'shipping_city', closest: () => ({}) } });
  check('details: a changed e-mail is set again, nothing repeated', t.personSets, [{ email: 'first@example.com', first_name: 'Ana', name: 'Ana' }, { email: 'second@example.com' }]);
}

// 3. "Record the visit": details typed before the answer wait for it; nothing is held or sent before.
{
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: true, beforeConsent: 'hold' }, new Map(), { querySelector: fieldsOf({ billing_email: 'held@example.com' }) });
  t.load();
  t.fire('change', { target: { name: 'billing_email', closest: () => ({}) } });
  check('details, record the visit: nothing before the answer', [t.personSets.length, setOn(t).length], [0, 0]);
  t.answer(true);
  check('details, record the visit: set after Accept', setOn(t).map((x) => x.email), ['held@example.com']);
}

// 4. A refusal after the details were set: nothing more is set.
{
  const fields = { billing_email: 'a@example.com' };
  const t = scenario({ ...every, consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' }, new Map(), { querySelector: fieldsOf(fields) });
  t.load();
  t.answer(true);
  t.answer(false);
  fields.billing_email = 'b@example.com';
  t.fire('change', { target: { name: 'billing_email', closest: () => ({}) } });
  check('details: after a refusal, a changed field is not set', setOn(t).map((x) => x.email), ['a@example.com']);
}

// 5. Test mode: posthog-js runs, the details call happens, nothing reaches PostHog.
{
  const t = scenario({ ...every, testMode: true }, new Map(), { querySelector: fieldsOf({ billing_email: 'test@example.com' }) });
  t.load();
  check('details, test mode: nothing sent', t.sent.length, 0);
}

// 6. Switched off, stored visitor, then a refusal: the cookie is deleted, as before the fix.
{
  const t = scenario({ consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' }, new Map(), { cookie: stored('old-1') });
  check('switched off, stored visitor: the cookie is untouched before the answer', !!t.cookie('ph_phc_test_posthog'), true);
  t.load();
  t.answer(false);
  check('switched off, stored visitor, refusal: the cookie is deleted', t.cookie('ph_phc_test_posthog'), null);
}

// 7. Switched off, an unreadable stored cookie: treated as a new visitor (store nothing until the answer).
{
  const t = scenario({ consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' }, new Map(), { cookie: 'ph_phc_test_posthog=%7Bbroken' });
  const c = t.config();
  check('switched off, unreadable stored cookie: as a new visitor', [c.persistence, c.bootstrap, c.opt_out_persistence_by_default], [undefined, undefined, true]);
}

// 8. The site's own opt-in (posthog_opt_in): unchanged by the fix (the site decides; nothing kept in memory).
{
  const t = scenario({ consent: 'posthog_opt_in', earlyStart: false, beforeConsent: 'off' }, new Map(), { cookie: stored('old-2') });
  const c = t.config();
  check('site\'s own opt-in, stored visitor: unchanged', [c.persistence, c.opt_out_persistence_by_default], [undefined, true]);
}

// 9. Switched off, stored anonymous visitor: kept as anonymous (not marked identified).
{
  const t = scenario({ consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch' }, new Map(), { cookie: stored('anon-1', { $user_state: 'anonymous' }) });
  check('switched off, stored anonymous visitor: kept anonymous', t.config().bootstrap, { distinctID: 'anon-1', isIdentifiedID: false });
}

// 10. Renamed keys: the logout marker resets posthog-js; the thank-you marker is stored under ph_wc_ty_.
{
  const t = scenario({ ...every, personDetails: false }, new Map(), { cookie: stored('signed-out') + '; ph_wc_logout=1' });
  const p = t.load();
  check('renamed logout marker: posthog-js reset, marker deleted', [p.get_distinct_id(), t.cookie('ph_wc_logout')], ['after-reset', null]);
}
{
  const t = scenario({ ...every, personDetails: false, linkReturn: true, events: { confirmationViewed: true }, orderLink: { order_id: '77', linkable: false } }, new Map(), { cookie: stored('buyer') });
  t.load();
  check('renamed thank-you marker: stored under ph_wc_ty_<order>', t.local.has('ph_wc_ty_77') && !t.local.has('w99phwc_ty_77'), true);
}

// 11. A field outside a checkout (newsletter, contact form) is never read as the buyer's.
{
  const t = scenario(every, new Map(), { querySelector: (sel) => (/billing_email|"email"/.test(sel) ? { value: 'newsletter@example.com', closest: () => null } : null) });
  t.load();
  t.fire('change', { target: { name: 'email', closest: () => null } });
  check('details: an e-mail outside a checkout is not set', t.personSets.length, 0);
}

// 12. Block checkout with "same address for billing": name and phone from the shipping fields.
{
  const fields = { email: 'block@example.com', 'shipping-first_name': 'Bea', 'shipping-last_name': 'Block', 'shipping-phone': '+32 470 00 00 00' };
  const t = scenario(every, new Map(), { querySelector: (sel) => { const m = /\[name="([^"]+)"\]/.exec(sel); return m && fields[m[1]] !== undefined ? { value: fields[m[1]], closest: () => ({}) } : null; } });
  t.load();
  check('details, block checkout: name and phone from the shipping fields', t.personSets, [{ email: 'block@example.com', first_name: 'Bea', last_name: 'Block', phone: '+32 470 00 00 00', name: 'Bea Block' }]);
}

// 13. Test mode: nothing reaches PostHog, session replay included.
{
  const t = scenario({ ...every, personDetails: false, testMode: true });
  const p = t.load();
  p.capture('$snapshot', { $snapshot_data: [] });
  p.capture('$pageview', {});
  check('test mode: no replay and no event reach PostHog', t.sent.length, 0);
}

// 14. A refusal removes the shop's visitor cookie also when "one visitor ID" was switched off after it was written.
{
  const t = scenario({ consent: 'cookiebot', earlyStart: true, beforeConsent: 'switch', visitorCookie: '' }, new Map(), { cookie: stored('v-1') + '; ph_wc_vid=0197aaaa-bbbb-7ccc-8ddd-eeeeffff0000' });
  t.load();
  t.answer(true);
  t.answer(false);
  check('refusal: the shop\'s visitor cookie is removed even with one visitor ID switched off', t.cookie('ph_wc_vid'), null);
}

done();
