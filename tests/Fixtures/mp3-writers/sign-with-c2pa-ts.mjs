// Signs an MP3 with c2pa-ts (an implementation independent of c2pa-rs), for step 230.
import 'reflect-metadata';
import * as fs from 'node:fs/promises';
import { X509Certificate } from '@peculiar/x509';
import { MP3 } from '@trustnxt/c2pa-ts/asset';
import { CoseAlgorithmIdentifier, LocalSigner } from '@trustnxt/c2pa-ts/cose';
import { DataHashAssertion, ManifestStore } from '@trustnxt/c2pa-ts/manifest';

const [source, target, certFile, keyFile, hashAlg] = process.argv.slice(2);
const pems = (await fs.readFile(certFile, 'utf8')).match(/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/g);
const [leaf, ...chain] = pems.map((p) => new X509Certificate(p));
const keyBase64 = (await fs.readFile(keyFile, 'utf8')).replace(/-{5}(BEGIN|END) .*-{5}/gm, '').replace(/\s/gm, '');
const signer = new LocalSigner(new Uint8Array(Buffer.from(keyBase64, 'base64')), CoseAlgorithmIdentifier.ES256, leaf, chain);

const asset = await MP3.create(await fs.readFile(source));
const store = new ManifestStore();
const manifest = store.createManifest({ assetFormat: 'audio/mpeg', instanceID: 'step-230', defaultHashAlgorithm: 'SHA-256', signer });
const dataHash = DataHashAssertion.create(hashAlg ?? 'SHA-256');
manifest.addAssertion(dataHash);
await asset.ensureManifestSpace(store.measureSize());
await dataHash.updateWithAsset(asset);
await manifest.sign(signer);
await asset.writeManifestJUMBF(store.getBytes());
await fs.writeFile(target, await asset.getDataRange());
console.log('written', target);
