-- ---------------------------------------------------------------------------
-- EDAS Lite initial schema
-- Copyright (C) 2026 华阳互联
-- SPDX-License-Identifier: AGPL-3.0-or-later
-- ---------------------------------------------------------------------------

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- 幂等键：键为客户端传入的 X-Idempotency-Key，值为首次响应 JSON
CREATE TABLE IF NOT EXISTS idempotency_keys (
    key        TEXT PRIMARY KEY,
    response   TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 订单：状态机 pending_payment -> processing -> completed | failed
CREATE TABLE IF NOT EXISTS orders (
    id                TEXT PRIMARY KEY,
    domain            TEXT NOT NULL,
    years             INTEGER NOT NULL DEFAULT 1,
    registrar         TEXT NOT NULL,
    status            TEXT NOT NULL DEFAULT 'pending_payment',
    amount            REAL NOT NULL DEFAULT 0,
    currency          TEXT NOT NULL DEFAULT 'USD',
    registrar_order_id TEXT,
    error_code        TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_orders_domain ON orders (domain);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status);

-- 域名归属映射：查询时据此路由到对应注册商
CREATE TABLE IF NOT EXISTS domain_registrar_map (
    domain     TEXT PRIMARY KEY,
    registrar  TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
