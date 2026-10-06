<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;

it('withStoreReached: the same fault when it already says so, a new one that keeps the message and the cause when not (step 253)', function (): void {
    $reached = new ContainerException('a fault in the store', storeReached: true);
    $before = new ContainerException('a fault before the store', storeReached: true);

    $same = $reached->withStoreReached(true);
    $changed = $before->withStoreReached(false);

    expect($same)->toBe($reached)
        ->and($changed)->not->toBe($before)
        ->and($changed->storeReached)->toBeFalse()
        ->and($changed->getMessage())->toBe('a fault before the store')
        ->and($changed->getPrevious())->toBe($before)
        ->and((new ContainerException('x', storeReached: false))->withStoreReached(true)->storeReached)->toBeTrue();
})->group('SPEC-013');
