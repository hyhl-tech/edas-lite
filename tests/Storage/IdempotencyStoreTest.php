<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Tests\Storage;

use Edas\Lite\Storage\Database;
use Edas\Lite\Storage\IdempotencyStore;
use PHPUnit\Framework\TestCase;

/**
 * 幂等键的行为是整个防重复扣费逻辑的地基，这里覆盖四条关键语义：
 * 未写入返回 null、先写入者为准、占位排他、回填覆盖。
 */
final class IdempotencyStoreTest extends TestCase
{
    private Database $db;

    private IdempotencyStore $store;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->db->execute(
            'CREATE TABLE idempotency_keys (
                key TEXT PRIMARY KEY,
                response TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $this->store = new IdempotencyStore($this->db);
    }

    public function testMissingKeyReturnsNull(): void
    {
        self::assertNull($this->store->get('missing'));
        self::assertFalse($this->store->has('missing'));
    }

    public function testRememberThenGet(): void
    {
        $payload = ['order' => ['id' => 'ord_1'], 'result' => ['success' => true]];

        $this->store->remember('k1', $payload);

        self::assertTrue($this->store->has('k1'));
        self::assertSame($payload, $this->store->get('k1'));
    }

    public function testFirstWriteWins(): void
    {
        $this->store->remember('k2', ['v' => 1]);
        $this->store->remember('k2', ['v' => 2]);

        self::assertSame(['v' => 1], $this->store->get('k2'));
    }

    public function testReserveIsExclusive(): void
    {
        self::assertTrue($this->store->reserve('k3'));
        self::assertFalse($this->store->reserve('k3'));
        self::assertTrue($this->store->isPending('k3'));
    }

    public function testCommitOverwritesPlaceholder(): void
    {
        $this->store->reserve('k4');
        $this->store->commit('k4', ['done' => true]);

        self::assertFalse($this->store->isPending('k4'));
        self::assertSame(['done' => true], $this->store->get('k4'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->store->remember('k5', ['v' => 1]);
        $this->store->forget('k5');

        self::assertNull($this->store->get('k5'));
    }

    public function testPruneRemovesExpiredKeys(): void
    {
        $this->store->remember('old', ['v' => 1]);
        $this->store->remember('fresh', ['v' => 2]);

        $this->db->execute(
            "UPDATE idempotency_keys SET created_at = datetime('now', '-30 days') WHERE key = 'old'"
        );

        $removed = $this->store->prune(7);

        self::assertSame(1, $removed);
        self::assertNull($this->store->get('old'));
        self::assertSame(['v' => 2], $this->store->get('fresh'));
    }
}
