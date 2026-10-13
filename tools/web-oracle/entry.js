// Browser entry: window.verifyWeb(url, mime, settings) -> c2pa-web's reader JSON, or null for no manifest.
import { createC2pa, Reader } from '@contentauth/c2pa-web/inline';
import { Context } from '@contentauth/c2pa-utilities';

const c2paPromise = createC2pa();
window.verifyWeb = async (url, mime, settings) => {
  const c2pa = await c2paPromise;
  const blob = await (await fetch(url)).blob();
  try {
    const reader = await Reader.fromBlob(c2pa, mime, blob, new Context(settings));
    if (!reader) return { noManifest: true };
    const json = await reader.json();
    await reader.free();
    return json;
  } catch (e) {
    return { error: String(e?.message ?? e) };
  }
};
