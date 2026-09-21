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
 * and NO_TIMESTAMP (validity judged at now until M6: the Truepic leaves lived
 * one day).
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
const SPEC013_PUBLIC_NO_TIMESTAMP = ['truepic-20230212-camera', 'truepic-20230212-landscape', 'truepic-20230212-library'];
