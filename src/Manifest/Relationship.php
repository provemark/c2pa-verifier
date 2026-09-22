<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

/**
 * How an ingredient stands to the asset that used it (C2PA 2.4 §18.16.3):
 * `parentOf` — the asset is derived from it (also the update manifest's
 * link); `componentOf` — the asset is composed of it; `inputTo` — it was
 * fed to a process. Any other value is `assertion.ingredient.malformed`
 * (§15.11.3.2).
 */
enum Relationship: string
{
    case ParentOf = 'parentOf';
    case ComponentOf = 'componentOf';
    case InputTo = 'inputTo';
}
