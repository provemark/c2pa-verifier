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
