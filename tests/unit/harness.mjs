// Runs the browser module in a sandbox with a fake posthog-js (it runs before_send like the real one),
// a cookie jar and session storage. Shared by hold.mjs (consent modes) and visitor.mjs (one visitor ID).
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../99trace-integration-with-posthog-for-woocommerce/assets/js/99trace.js', import.meta.url), 'utf8');

/** document.cookie as a browser keeps it: one value per name, `max-age=0` deletes. */
function cookieJar(initial = '') {
  const jar = new Map();
  const writes = [];
  const set = (line) => {
    const [pair, ...attrs] = line.split(';').map((x) => x.trim());
    const eq = pair.indexOf('=');
    const name = pair.slice(0, eq);
    const value = pair.slice(eq + 1);
    if (attrs.some((a) => /^max-age=0$/i.test(a) || /^expires=.*1970/i.test(a))) jar.delete(name);
    else jar.set(name, value);
  };
  initial.split(/;\s*/).filter(Boolean).forEach(set);
  return {
    jar,
    writes,
    get() { return [...jar].map(([k, v]) => `${k}=${v}`).join('; '); },
    set(line) { writes.push(line); set(line); },
  };
}

export function scenario(cfgOverrides, storage = new Map(), extras = {}) {
  const cfg = { token: 'phc_test', apiHost: 'https://eu.i.posthog.com', consent: 'cookiebot', earlyStart: true, beforeConsent: 'hold', stripParams: ['key'], ...cfgOverrides };
  const handlers = {};
  const scripts = [];
  const local = extras.local || new Map();
  const store = (m) => ({
    get length() { return m.size; }, key(i) { return [...m.keys()][i] ?? null; },
    getItem(k) { return m.has(k) ? m.get(k) : null; }, setItem(k, v) { m.set(k, String(v)); }, removeItem(k) { m.delete(k); },
  });
  const window = {
    w99phwc: cfg,
    location: { hostname: 'shop.example', href: 'https://shop.example/', protocol: 'https:', search: '' },
    addEventListener(n, f) { (handlers[n] = handlers[n] || []).push(f); },
    setTimeout() { return 0; }, setInterval() { return 0; }, clearInterval() {},
    localStorage: store(local),
    sessionStorage: store(storage),
    console,
    ...(extras.window || {}),
  };
  const cookies = cookieJar(extras.cookie || '');
  const document = {
    get cookie() { return cookies.get(); }, set cookie(v) { cookies.set(v); },
    readyState: 'complete', addEventListener(n, f) { (handlers[n] = handlers[n] || []).push(f); }, querySelector(sel) { return extras.querySelector ? extras.querySelector(sel) : null; }, querySelectorAll() { return []; },
    createElement() { const el = { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } }; scripts.push(el); return el; },
    getElementsByTagName() { return [{ parentNode: { insertBefore() {} } }]; },
    ...(extras.document || {}),
  };
  vm.runInNewContext(src, { window, document, RegExp, Object, Array, JSON, String, Date, Math, Uint8Array, Blob });
  const sent = [];
  const calls = [];
  const aliases = [];
  const personSets = [];
  let inst = null;
  /** posthog-js arrives: its ID is the bootstrap ID, else the one stored earlier, else a fresh 'visitor-1'. */
  function load() {
    const config = window.posthog._i[0][1];
    const stored = (() => {
      const m = /(?:^|;\s*)ph_phc_test_posthog=([^;]*)/.exec(cookies.get());
      try { return m ? JSON.parse(decodeURIComponent(m[1])).distinct_id : null; } catch (e) { return null; }
    })();
    inst = {
      __loaded: true, config, persistence: { props: {} },
      id: (config.bootstrap && config.bootstrap.distinctID) || stored || 'visitor-1',
      userState: 'anonymous',
      capturingNow: !config.opt_out_capturing_by_default,
      capture(event, props = {}, opts = {}) {
        calls.push({ event, opts });
        const ev = { event, properties: { distinct_id: 'visitor-1', $session_id: 'sess-1', ...props }, timestamp: opts.timestamp || new Date(), $set: opts.$set };
        const out = config.before_send(ev);
        if (out && this.capturingNow) sent.push(out);
      },
      set_config(c) { Object.assign(config, c); },
      get_distinct_id() { return this.id; },
      get_property(k) { return k === '$user_state' ? this.userState : k === '$device_id' ? this.id : null; },
      get_session_id() { return 'sess-1'; },
      is_capturing() { return this.capturingNow; }, has_opted_out_capturing() { return !this.capturingNow; },
      opt_out_capturing() { this.optedOut = true; this.capturingNow = false; }, opt_in_capturing() { this.capturingNow = true; }, clear_opt_in_out_capturing() {},
      calculateEventProperties() { return {}; }, onFeatureFlags() {}, getFeatureFlag() {},
      reset() { this.id = 'after-reset'; this.userState = 'anonymous'; },
      identify(id) { this.id = id; this.userState = 'identified'; },
      alias(a) { aliases.push({ alias: a, distinct_id: this.id }); },
      register() {},
      setPersonProperties(props) { personSets.push(props); this.capture('$set', { $set: props }); },
    };
    // As posthog-js's loader does: the loaded callback runs inside init, before window.posthog is replaced.
    config.loaded(inst);
    window.posthog = inst;
    return inst;
  }
  function answer(statistics) {
    window.Cookiebot = { hasResponse: true, consent: { statistics } };
    (handlers[statistics ? 'CookiebotOnAccept' : 'CookiebotOnDecline'] || []).forEach((f) => f());
  }
  function fire(name, ev) { (handlers[name] || []).forEach((f) => f(ev)); }
  return {
    cfg, window, document, sent, calls, aliases, personSets, fire, load, answer, scripts, storage, cookies, local,
    config: () => (inst ? inst.config : window.posthog && window.posthog._i && window.posthog._i[0][1]),
    cookie: (name) => (cookies.jar.has(name) ? decodeURIComponent(cookies.jar.get(name)) : null),
  };
}

let failed = 0;
export function check(name, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (!ok) failed++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : `\n     got  ${JSON.stringify(got)}\n     want ${JSON.stringify(want)}`}`);
}
export function done() {
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
}
