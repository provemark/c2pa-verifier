// Compares c2pa-web with c2patool and with this verifier, per mode: validation_state, failure codes, all codes.
import { readFileSync } from 'node:fs';

const load = (engine, mode) => JSON.parse(readFileSync(new URL(`./out/${engine}-${mode}.json`, import.meta.url)));
const summary = (r) => {
  if (!r || r.noManifest) return { state: 'no-manifest', fail: [], all: [] };
  if (r.error) return { state: 'error', fail: [], all: [] };
  const vr = r.validation_results ?? {};
  const groups = [vr.activeManifest ?? {}, ...(vr.ingredientDeltas ?? []).map((d) => d.validationDeltas ?? {})];
  const codes = (kinds) => [...new Set(groups.flatMap((g) => kinds.flatMap((k) => (g[k] ?? []).map((s) => s.code))))].sort();
  return { state: r.validation_state, fail: codes(['failure']), all: codes(['success', 'informational', 'failure']) };
};
const same = (a, b) => a.length === b.length && a.every((x, i) => x === b[i]);
const minus = (a, b) => a.filter((x) => !b.includes(x));

for (const mode of ['none', 'trust']) {
  const engines = Object.fromEntries(['c2pa-web', 'c2patool', 'verifier'].map((e) => [e, load(e, mode)]));
  const files = Object.keys(engines['c2pa-web']);
  const s = Object.fromEntries(Object.entries(engines).map(([e, d]) => [e, Object.fromEntries(files.map((f) => [f, summary(d[f])]))]));
  console.log(`\n== ${mode === 'none' ? 'without trust settings' : 'with trust settings'}: ${files.length} files`);
  for (const [a, b] of [['c2pa-web', 'c2patool'], ['c2pa-web', 'verifier']]) {
    const st = files.filter((f) => s[a][f].state !== s[b][f].state);
    const fc = files.filter((f) => !same(s[a][f].fail, s[b][f].fail));
    const ac = files.filter((f) => !same(s[a][f].all, s[b][f].all));
    console.log(`${a} vs ${b}: state differs ${st.length}, failure codes differ ${fc.length}, any code differs ${ac.length}`);
    for (const f of [...new Set([...st, ...fc])].sort()) {
      const x = s[a][f], y = s[b][f];
      console.log(`  ${f}: ${x.state} vs ${y.state}; only ${a}: [${minus(x.fail, y.fail)}]; only ${b}: [${minus(y.fail, x.fail)}]`);
    }
  }
}
