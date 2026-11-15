<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Storage;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 极简 PDO SQLite 封装。
 *
 * 只负责连接与常用查询辅助，不含任何业务语义，
 * 便于将来替换存储实现而不影响适配器层。
 */
final class Database
{
    private PDO $pdo;

    private string $path = ':memory:';

    /**
     * @param string|null $path 数据库文件路径；为空时回退到 DB_PATH 环境变量
     */
    public function __construct(?string $path = null)
    {
        $path = $path ?? (string) (self::env('DB_PATH') ?: 'storage/edas.sqlite');

        if ($path !== ':memory:') {
            $path = self::absolute($path);
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('无法创建数据库目录: %s', $dir));
            }
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败: ' . $e->getMessage(), 0, $e);
        }

        $this->path = $path;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 执行写入语句，返回受影响行数。
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * 查询单行，不存在返回 null。
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 查询多行。
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * 查询单列单值。
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * 在事务中执行回调，异常自动回滚。
     *
     * @template T
     * @param callable(self):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * 判断某张表是否存在，用于健康检查。
     */
    public function hasTable(string $table): bool
    {
        $row = $this->fetch(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        );

        return $row !== null;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        $base = $_ENV['EDAS_BASE_PATH'] ?? $_SERVER['EDAS_BASE_PATH'] ?? dirname(__DIR__, 2);

        return rtrim((string) $base, '/\\') . '/' . ltrim($path, '/\\');
    }
}
