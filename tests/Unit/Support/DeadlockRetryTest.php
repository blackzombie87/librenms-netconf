<?php

use SafferIt\LibrenmsNetconf\Support\DeadlockRetry;

/** A driver exception the way PDO shapes it: SQLSTATE in code position 0 of errorInfo. */
function lockConflict(int $code = 1213, string $state = '40001'): \PDOException
{
    $e = new \PDOException("SQLSTATE[$state]: Serialization failure: $code Deadlock found when trying to get lock; try restarting transaction");
    $e->errorInfo = [$state, $code, 'Deadlock found when trying to get lock; try restarting transaction'];

    return $e;
}

it('runs a write again after a deadlock and returns its result', function () {
    $calls = 0;
    $pauses = [];

    $result = DeadlockRetry::run(function () use (&$calls) {
        if (++$calls < 3) {
            throw lockConflict();
        }

        return 'stored';
    }, 4, function (int $attempt) use (&$pauses) {
        $pauses[] = $attempt;
    });

    expect($result)->toBe('stored')->and($calls)->toBe(3)->and($pauses)->toBe([1, 2]);
});

it('gives up after the last attempt and rethrows the deadlock', function () {
    $calls = 0;

    $thrown = null;
    try {
        DeadlockRetry::run(function () use (&$calls) {
            $calls++;
            throw lockConflict();
        }, 3, fn () => null);
    } catch (\PDOException $thrown) {
    }

    expect($thrown)->toBeInstanceOf(\PDOException::class)->and($calls)->toBe(3);
});

it('does not repeat an error that is not a lock conflict', function () {
    $calls = 0;

    $thrown = null;
    try {
        DeadlockRetry::run(function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('no room for this metric');
        }, 4, fn () => null);
    } catch (\RuntimeException $thrown) {
    }

    expect($thrown)->toBeInstanceOf(\RuntimeException::class)->and($calls)->toBe(1);
});

it('recognises deadlocks and lock waits wherever the code sits', function () {
    $wrapped = new \RuntimeException('wrapped', 0, lockConflict());
    $timeout = new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
    $timeout->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

    expect(DeadlockRetry::isLockConflict(lockConflict()))->toBeTrue()
        ->and(DeadlockRetry::isLockConflict($wrapped))->toBeTrue()
        ->and(DeadlockRetry::isLockConflict($timeout))->toBeTrue()
        ->and(DeadlockRetry::isLockConflict(new \RuntimeException('SQLSTATE[40001]: Serialization failure')))->toBeTrue()
        ->and(DeadlockRetry::isLockConflict(new \RuntimeException('Duplicate entry')))->toBeFalse();
});

it('describes a database failure without the statement and its bound values', function () {
    $laravel = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found (Connection: mysql, Host: localhost, SQL: insert into `netconf_evpn_mac` (`mac_address`) values (98eecbd05b5b))');

    expect(DeadlockRetry::describe(lockConflict()))->toBe('SQLSTATE[40001] 1213 Deadlock found when trying to get lock; try restarting transaction')
        ->and(DeadlockRetry::describe($laravel))->toBe('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found')
        ->and(DeadlockRetry::describe(new \RuntimeException('plain message')))->toBe('plain message');
});
