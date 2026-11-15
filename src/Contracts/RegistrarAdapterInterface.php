<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Contracts;

/**
 * 注册商适配器统一契约。
 *
 * 本接口刻意保持纯净：实现方只能依赖 HTTP 客户端与配置数组，
 * 不得依赖数据库、订单、路由或任何全局状态，以便将来抽取为独立库。
 *
 * 所有返回值均为数组，结构见各处注释。实现方不抛业务异常，
 * 业务失败以 success=false + registrarErrorCode 表达；仅在配置错误、
 * 网络不可用等无法继续的情况下抛出异常，由 RegistrarRouter 决定是否换一家重试。
 */
interface RegistrarAdapterInterface
{
    /**
     * 批量查询域名可用性与价格。
     *
     * @param string[] $domains
     * @return array<string, array{
     *     domain: string,
     *     available: bool,
     *     registerPrice: float|null,
     *     renewPrice: float|null,
     *     currency: string,
     *     premium: bool,
     *     reason: string|null
     * }> 以域名为键
     */
    public function checkAvailability(array $domains): array;

    /**
     * 注册域名。
     *
     * @param array $params 可选：nameservers(string[])、contacts(array)、private(bool)、autoRenew(bool)、
     *                      idempotencyKey(string)、promoCode(string)
     * @return array{
     *     success: bool,
     *     domain: string,
     *     orderId: string|null,
     *     amount: float|null,
     *     currency: string,
     *     partialSuccess: bool,
     *     warnings: string[],
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function register(string $domain, int $years, array $params): array;

    /**
     * 续费域名。
     *
     * @param array $params 可选：idempotencyKey(string)
     * @return array{
     *     success: bool,
     *     domain: string,
     *     orderId: string|null,
     *     amount: float|null,
     *     currency: string,
     *     partialSuccess: bool,
     *     warnings: string[],
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function renew(string $domain, int $years): array;

    /**
     * 提交转入。
     *
     * @param array $params 可选：years(int)、contacts(array)、idempotencyKey(string)
     * @return array{
     *     success: bool,
     *     domain: string,
     *     orderId: string|null,
     *     amount: float|null,
     *     currency: string,
     *     partialSuccess: bool,
     *     warnings: string[],
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function transfer(string $domain, string $authCode, array $params): array;

    /**
     * 查询已注册域名详情。
     *
     * @return array{
     *     domain: string,
     *     registrar: string,
     *     status: string,
     *     expireDate: string|null,
     *     nameservers: string[],
     *     locked: bool,
     *     autoRenew: bool
     * }
     */
    public function getDomainInfo(string $domain): array;

    /**
     * 读取 DNS 记录。
     *
     * @return array{
     *     domain: string,
     *     records: array<int, array{
     *         id: string|null,
     *         type: string,
     *         host: string,
     *         answer: string,
     *         ttl: int,
     *         priority: int|null
     *     }>
     * }
     */
    public function getDnsRecords(string $domain): array;

    /**
     * 写入 DNS 记录（幂等 upsert：同 host + type 已存在则更新，否则新增）。
     *
     * @param array<int, array{type: string, host: string, answer: string, ttl?: int, priority?: int}> $records
     * @return array{
     *     success: bool,
     *     domain: string,
     *     applied: int,
     *     warnings: string[],
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function setDnsRecords(string $domain, array $records): array;

    /**
     * 删除单条 DNS 记录。
     *
     * @return array{
     *     success: bool,
     *     domain: string,
     *     deleted: bool,
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function deleteDnsRecord(string $domain, string $recordId): array;

    /**
     * 查询转入进度。已完成的转入应返回 status=completed。
     *
     * @return array{
     *     domain: string,
     *     registrar: string,
     *     status: string,
     *     requestedDate: string|null,
     *     finalDate: string|null,
     *     message: string
     * }
     */
    public function getTransferStatus(string $domain): array;

    /**
     * 取得转移授权码（EPP code）。
     *
     * @return array{
     *     success: bool,
     *     domain: string,
     *     authCode: string|null,
     *     registrarErrorCode: string|null,
     *     message: string
     * }
     */
    public function getEppCode(string $domain): array;

    /**
     * 注册商标识，用于路由与审计。
     */
    public function getRegistrarName(): string;
}
