<?php

declare(strict_types=1);

/*
 * Pest bootstrap. Every test file is tagged with the spec it covers via
 * ->group('SPEC-###'); bin/spec-check.php (M0.3) enforces that. Nothing is
 * configured here yet on purpose: helpers arrive with the spec that needs
 * them.
 */

/*
 * Every c2patool JSON recorded without trust settings (steps 14, 21, 23, 24,
 * 26) and the file it was recorded on — SPEC-013 AC10's drift alarm, re-run
 * by SPEC-014 AC10 with the full trust settings. Grows with every step that
 * records a JSON.
 */
const SPEC013_CORPUS = [
    'jpg' => 'fixture-signed.jpg',
    'png' => 'fixture-signed.png',
    'webp' => 'fixture-signed.webp',
    'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg',
    'variants/claim-title-changed' => 'cose/claim-title-changed.png',
    'variants/signature-changed' => 'cose/signature-changed.png',
    'variants/json-broken' => 'claim/json-broken.png',
    'variants/pixel-changed' => 'binding/pixel-changed.png',
    'variants/exclusions-overlap' => 'binding/exclusions-overlap.png',
    'variants/hashed-uri-changed' => 'binding/hashed-uri-changed.png',
    'variants/hashed-uris-two-changed' => 'binding/hashed-uris-two-changed.png',
    'variants/hashed-uri-truncated' => 'binding/hashed-uri-truncated.png',
    'variants/uri-alg-sha384' => 'binding/uri-alg-sha384.png',
    'variants/claim-alg-sha1' => 'binding/claim-alg-sha1.png',
    'variants/claim-redacted' => 'binding/claim-redacted.png',
    'variants/exclusion-extra' => 'binding/exclusion-extra.png',
    'variants/exclusions-unsorted' => 'binding/exclusions-unsorted.png',
    'variants/hash-as-text' => 'binding/hash-as-text.png',
    'variants/exclusions-too-many' => 'binding/exclusions-too-many.png',
    'variants/alg-missing' => 'binding/alg-missing.png',
    'variants/alg-sha384' => 'binding/alg-sha384.png',
    'variants/hard-bindings-two' => 'binding/hard-bindings-two.png',
];

/*
 * The official test files (c2pa-org/public-testfiles, legacy/1.4/image/jpeg,
 * step 36) with a c2patool JSON — SPEC-013 AC11's second drift alarm. The
 * expectation is c2patool's state, except where this verifier is stricter on
 * purpose, named here so that the milestone closing the gap must remove the
 * name: MULTI (more than one manifest → Invalid until M7, SPEC-013 amendment 5)
 * and TSA_NOT_CONFIGURED (the file is expired at now here because its TSA
 * reaches no configured anchor, while c2patool trusts that TSA without one —
 * SPEC-017 AC6 measures the same files with the anchor configured).
 */
const SPEC013_PUBLIC_CORPUS = [
    'adobe-20220124-C', 'adobe-20220124-CA', 'adobe-20220124-CACA', 'adobe-20220124-CACAICAICICA', 'adobe-20220124-CAI',
    'adobe-20220124-CAIAIIICAICIICAIICICA', 'adobe-20220124-CAICA', 'adobe-20220124-CAICAI', 'adobe-20220124-CI', 'adobe-20220124-CICA',
    'adobe-20220124-CICACACA', 'adobe-20220124-CIE-sig-CA', 'adobe-20220124-CII', 'adobe-20220124-E-clm-CAICAI', 'adobe-20220124-E-dat-CA',
    'adobe-20220124-E-sig-CA', 'adobe-20220124-E-uri-CA', 'adobe-20220124-E-uri-CIE-sig-CA', 'adobe-20220124-XCA', 'adobe-20220124-XCI',
    'nikon-20221019-building', 'truepic-20230212-camera', 'truepic-20230212-landscape', 'truepic-20230212-library',
];
const SPEC013_PUBLIC_MULTI = [
    'adobe-20220124-CACA', 'adobe-20220124-CACAICAICICA', 'adobe-20220124-CAIAIIICAICIICAIICICA', 'adobe-20220124-CAICA', 'adobe-20220124-CAICAI',
    'adobe-20220124-CICA', 'adobe-20220124-CICACACA', 'adobe-20220124-CIE-sig-CA', 'adobe-20220124-E-clm-CAICAI', 'adobe-20220124-E-uri-CIE-sig-CA',
];
const SPEC013_PUBLIC_TSA_NOT_CONFIGURED = ['truepic-20230212-camera', 'truepic-20230212-landscape', 'truepic-20230212-library'];

/*
 * The c2pa-rs fixtures (sdk/tests/fixtures at 58eac79, step 39) with a c2patool
 * JSON — SPEC-013 AC12's third drift alarm. Named exceptions, each removed by
 * the milestone that closes it: MULTI (M7), TSA_NOT_CONFIGURED (expired at now
 * under `full`, whose anchors no DigiCert TSA reaches — SPEC-017 AC10 shows the
 * cross-certificate un-expires exp-test1), REMOTE (a manifest
 * c2patool fetched over the network — never here), CAWG (an identity assertion
 * this verifier does not validate; a later spec).
 */
const SPEC013_RS_CORPUS = [
    'C', 'CA', 'CACA', 'CACAE-uri-CA', 'CA_ct', 'CIE-sig-CA', 'C_with_CAWG_data', 'E-sig-CA', 'XCA',
    'adobe-20220124-E-clm-CAICAI', 'boxhash', 'cloud', 'legacy_ingredient_hash', 'ocsp', 'ocsp_with_assertion', 'update_manifest', 'exp-test1',
];
const SPEC013_RS_MULTI = ['CACA', 'CACAE-uri-CA', 'CIE-sig-CA', 'legacy_ingredient_hash', 'update_manifest', 'ocsp', 'ocsp_with_assertion'];
const SPEC013_RS_TSA_NOT_CONFIGURED = ['ocsp', 'ocsp_with_assertion', 'exp-test1'];
const SPEC013_RS_REMOTE = ['cloud'];
const SPEC013_RS_CAWG = ['C_with_CAWG_data'];

/*
 * Files from other writers (step 43) — SPEC-013 AC13's fourth drift alarm.
 * Named exceptions as above: MULTI (M7), REMOTE (a manifest declared by URL,
 * never fetched), TSA_NOT_CONFIGURED (expired at now; the DigiCert TSA
 * reaches no anchor without settings — SPEC-017 AC11 lifts it with one).
 */
const SPEC013_WRITERS_CORPUS = ['openai-20260826-c2pa_2x', 'amazon-20240925-titan-g1', 'trustnxt-20260113-icon-signed-timestamp', 'adobe-20260304-photoshop-remote-manifest', 'c2pa-rs-cawg_ica', 'google-20250919-pixel10-npld-picnic-table', 'adobe-20260425-lightroom-classic-church'];
const SPEC013_WRITERS_MULTI = ['c2pa-rs-cawg_ica'];
const SPEC013_WRITERS_REMOTE = ['adobe-20260304-photoshop-remote-manifest'];
const SPEC013_WRITERS_TSA_NOT_CONFIGURED = ['amazon-20240925-titan-g1', 'google-20250919-pixel10-npld-picnic-table'];   // Pixel: a three-month signer, valid at its Google stamp (step 46)
