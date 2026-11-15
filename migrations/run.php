<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * 迁移执行器：按文件名顺序执行 migrations 目录下的所有 .sql 文件。
 * 用法：php migrations/run.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

// 读取 .env（存在才读），保证 DB_PATH 与运行时一致
$envFile = $root . '/.env';
if (is_file($envFile) && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$dbPath = $_ENV['DB_PATH'] ?? $_SERVER['DB_PATH'] ?? getenv('DB_PATH');
if (!is_string($dbPath) || $dbPath === '') {
    $dbPath = 'storage/edas.sqlite';
}

$dbPath = str_starts_with($dbPath, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $dbPath)
    ? $dbPath
    : $root . '/' . ltrim($dbPath, '/\\');

$dir = dirname($dbPath);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建数据库目录: {$dir}\n");
    exit(1);
}

$files = glob($root . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "未找到任何迁移文件\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            continue;
        }

        // PDO SQLite 可以一次执行多语句
        $pdo->exec($sql);
        echo '已执行: ' . basename($file) . PHP_EOL;
    }

    echo '数据库就绪: ' . $dbPath . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '迁移失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
