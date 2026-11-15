<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Storage;

/**
 * 幂等键存储。
 *
 * 键来自客户端请求头 X-Idempotency-Key。同一个键只应产生一次真实的上游副作用，
 * 后续请求直接返回首次保存的响应体，用于防止重复扣费与重复注册。
 */
final class IdempotencyStore
{
    public function __construct(private Database $db)
    {
    }

    /**
     * 读取首次结果；不存在返回 null。
     */
    public function get(string $key): ?array
    {
        $row = $this->db->fetch(
            'SELECT response FROM idempotency_keys WHERE key = :key',
            ['key' => $key]
        );

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['response'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function has(string $key): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM idempotency_keys WHERE key = :key',
            ['key' => $key]
        ) !== null;
    }

    /**
     * 保存首次结果。已存在时不覆盖（先写入者为准）。
     */
    public function remember(string $key, array $response): void
    {
        $this->db->execute(
            'INSERT INTO idempotency_keys (key, response, created_at)
             VALUES (:key, :response, :created_at)
             ON CONFLICT(key) DO NOTHING',
            [
                'key'       => $key,
                'response'  => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * 先占位再执行，避免并发下两个请求同时穿透。
     * 返回 true 表示占位成功（应当继续执行真实操作）。
     */
    public function reserve(string $key): bool
    {
        $affected = $this->db->execute(
            'INSERT INTO idempotency_keys (key, response, created_at)
             VALUES (:key, :response, :created_at)
             ON CONFLICT(key) DO NOTHING',
            [
                'key'        => $key,
                'response'   => json_encode(['_pending' => true], JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return $affected > 0;
    }

    /**
     * 占位后回填真实结果。
     */
    public function commit(string $key, array $response): void
    {
        $this->db->execute(
            'UPDATE idempotency_keys SET response = :response WHERE key = :key',
            [
                'key'      => $key,
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /**
     * 判断某个键是否仍处于占位状态。
     */
    public function isPending(string $key): bool
    {
        $value = $this->get($key);

        return is_array($value) && ($value['_pending'] ?? false) === true;
    }

    /**
     * 清理占位（操作失败且不希望阻止重试时使用）。
     */
    public function forget(string $key): void
    {
        $this->db->execute('DELETE FROM idempotency_keys WHERE key = :key', ['key' => $key]);
    }

    /**
     * 清理过期键，默认保留 7 天。
     */
    public function prune(int $days = 7): int
    {
        return $this->db->execute(
            "DELETE FROM idempotency_keys WHERE created_at < datetime('now', :modifier)",
            ['modifier' => sprintf('-%d days', max(1, $days))]
        );
    }
}
