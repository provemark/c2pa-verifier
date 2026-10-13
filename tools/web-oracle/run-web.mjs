// Runs @contentauth/c2pa-web 0.15.3 in headless Chrome over files.json in two modes, writes out/c2pa-web-<mode>.json.
// Every request is answered locally; anything else is aborted and logged, so no network is used.
import { chromium } from 'playwright-core';
import { readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const fx = `${root}/tests/Fixtures`;
const files = JSON.parse(readFileSync(new URL('./out/files.json', import.meta.url)));
const bundle = readFileSync(new URL('./out/bundle.js', import.meta.url), 'utf8');
const mimes = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };

// c2patool's snake_case settings file -> c2pa-web's camelCase Settings, network off.
const webSettings = (path) => {
  const s = path ? JSON.parse(readFileSync(path)) : {};
  const t = s.trust ?? {};
  const trust = {};
  if (t.trust_anchors) trust.trustAnchors = t.trust_anchors;
  if (t.trust_config) trust.trustConfig = t.trust_config;
  if (t.allowed_list) trust.allowedList = t.allowed_list;
  if (t.user_anchors) trust.userAnchors = t.user_anchors;
  const verify = { remoteManifestFetch: false, ocspFetch: false };
  if (s.verify?.verify_trust !== undefined) verify.verifyTrust = s.verify.verify_trust;
  return { trust, verify };
};
const trustFile = (f) => (f.corpus === 'matrix' ? `${fx}/matrix/test-roots.settings.json` : `${fx}/trust/full-plus-digicert-g4.settings.json`);

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
const blocked = [];
await page.route('**/*', (route) => {
  const url = new URL(route.request().url());
  if (url.origin === 'http://oracle.local') {
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>oracle</title>' });
    if (url.pathname === '/bundle.js') return route.fulfill({ contentType: 'text/javascript', body: bundle });
    if (url.pathname.startsWith('/fx/')) return route.fulfill({ body: readFileSync(`${fx}/${decodeURIComponent(url.pathname.slice(4))}`) });
  }
  blocked.push(url.href);
  return route.abort();
});
page.on('console', (m) => m.type() === 'error' && console.error('[page]', m.text()));
await page.goto('http://oracle.local/');
await page.addScriptTag({ url: '/bundle.js' });

for (const mode of ['none', 'trust']) {
  const res = {};
  for (const f of files) {
    const ext = f.rel.split('.').pop().toLowerCase();
    const settings = webSettings(mode === 'trust' ? trustFile(f) : null);
    res[f.rel] = await page.evaluate(([u, m, s]) => window.verifyWeb(u, m, s), [`/fx/${encodeURIComponent(f.rel)}`, mimes[ext], settings]);
  }
  writeFileSync(new URL(`./out/c2pa-web-${mode}.json`, import.meta.url), JSON.stringify(res));
  console.log(mode, 'done');
}
console.log('blocked requests:', blocked.length, blocked.slice(0, 5));
await browser.close();
