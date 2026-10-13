// Runs c2patool ($C2PATOOL, else the one on PATH) and this verifier's command over files.json in two modes, writes out/<engine>-<mode>.json.
// Network is off for c2patool (no remote manifest, no OCSP); the verifier never fetches.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const root = new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const c2patool = process.env.C2PATOOL ?? 'c2patool';
const fx = `${root}/tests/Fixtures`;
const files = JSON.parse(readFileSync(new URL('./out/files.json', import.meta.url)));
const out = new URL('./out/', import.meta.url);
mkdirSync(out, { recursive: true });

const net = { remote_manifest_fetch: false, ocsp_fetch: false };
const trustFile = (f) => (f.corpus === 'matrix' ? `${fx}/matrix/test-roots.settings.json` : `${fx}/trust/full-plus-digicert-g4.settings.json`);
const toolSettings = (path) => {
  const s = path ? JSON.parse(readFileSync(path)) : {};
  s.verify = { ...(s.verify ?? {}), ...net };
  const p = new URL(`./out/settings-${path ? path.split('/').pop() : 'none.json'}`, import.meta.url).pathname;
  writeFileSync(p, JSON.stringify(s));
  return p;
};

const run = (cmd, args) => {
  try {
    return execFileSync(cmd, args, { maxBuffer: 1 << 28, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
  } catch (e) {
    return e.stdout?.toString() || JSON.stringify({ error: e.stderr?.toString().trim() || String(e) });
  }
};
const parse = (s) => { try { return JSON.parse(s); } catch { return { error: s.slice(0, 300) }; } };

for (const mode of ['none', 'trust']) {
  const tool = {}, php = {};
  for (const f of files) {
    const path = `${fx}/${f.rel}`;
    const settings = mode === 'trust' ? trustFile(f) : null;
    tool[f.rel] = parse(run(c2patool, [path, '--settings', toolSettings(settings)]));
    php[f.rel] = parse(run('php', [`${root}/bin/c2pa-verify`, ...(settings ? ['--settings', settings] : []), path]));
  }
  writeFileSync(new URL(`c2patool-${mode}.json`, out), JSON.stringify(tool));
  writeFileSync(new URL(`verifier-${mode}.json`, out), JSON.stringify(php));
  console.log(mode, 'done');
}
