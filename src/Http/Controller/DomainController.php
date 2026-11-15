<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Http\Controller;

use Edas\Lite\Http\Response;
use Edas\Lite\Order\OrderService;
use Edas\Lite\Router\RegistrarRouter;
use Throwable;

/**
 * 域名相关接口。
 *
 * 所有方法只做参数校验与结果整形，真正的差异都由 Router + Adapter 消化。
 */
final class DomainController
{
    public function __construct(
        private RegistrarRouter $router,
        private OrderService $orders
    ) {
    }

    /**
     * POST /api/v1/domains/check
     *
     * @param array{domain?: string, domains?: string[], registrar?: string} $body
     */
    public function check(array $body): void
    {
        $domains = $this->extractDomains($body);
        $registrar = isset($body['registrar']) && $body['registrar'] !== ''
            ? strtolower(trim((string) $body['registrar']))
            : null;

        if ($domains === []) {
            Response::error('invalid_argument', '缺少 domain 或 domains 参数', 422);

            return;
        }

        if (count($domains) > 50) {
            Response::error('invalid_argument', '单次最多查询 50 个域名', 422);

            return;
        }

        foreach ($domains as $domain) {
            if (!$this->isPlausibleDomain($domain)) {
                Response::error('invalid_argument', sprintf('域名格式不合法: %s', $domain), 422);

                return;
            }
        }

        try {
            $results = $this->router->checkAvailability($domains, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        if (count($results) === 1) {
            Response::success($results[0]);

            return;
        }

        Response::success(['results' => $results]);
    }

    /**
     * GET /api/v1/domains/{domain}
     */
    public function getInfo(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        $registrar = $this->orders->registrarFor($domain);

        try {
            $info = $this->router->getDomainInfo($domain, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        Response::success($info);
    }

    /**
     * GET /api/v1/domains/{domain}/dns
     */
    public function getDns(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        $registrar = $this->orders->registrarFor($domain);

        try {
            $records = $this->router->getDnsRecords($domain, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        Response::success($records);
    }

    /**
     * PUT /api/v1/domains/{domain}/dns
     *
     * @param array{records?: array<int, array{type?: string, host?: string, answer?: string, ttl?: int, priority?: int}>} $body
     */
    public function setDns(string $domain, array $body): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        $records = $body['records'] ?? null;
        if (!is_array($records) || $records === []) {
            Response::error('invalid_argument', 'records 必须是非空数组', 422);

            return;
        }

        $allowed = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                Response::error('invalid_argument', sprintf('records[%d] 不是对象', $index), 422);

                return;
            }

            $type = strtoupper(trim((string) ($record['type'] ?? '')));
            if (!in_array($type, $allowed, true)) {
                Response::error(
                    'invalid_argument',
                    sprintf('records[%d] 的 type 不受支持，允许值: %s', $index, implode('/', $allowed)),
                    422
                );

                return;
            }

            if (trim((string) ($record['answer'] ?? '')) === '') {
                Response::error('invalid_argument', sprintf('records[%d] 缺少 answer', $index), 422);

                return;
            }
        }

        $registrar = $this->orders->registrarFor($domain);

        try {
            $result = $this->router->setDnsRecords($domain, $records, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        // 部分成功（applied > 0 但有 warning）不视为整体失败，把 warnings 交给调用方
        if (($result['applied'] ?? 0) === 0 && ($result['success'] ?? false) === false) {
            Response::fromRegistrarFailure($result, 502);

            return;
        }

        Response::success($result);
    }

    /**
     * POST /api/v1/domains/{domain}/renew
     *
     * @param array{years?: int, registrar?: string} $body
     */
    public function renew(string $domain, array $body): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        $years = (int) ($body['years'] ?? 1);
        if ($years < 1 || $years > 10) {
            Response::error('invalid_argument', 'years 必须是 1 到 10 之间的整数', 422);

            return;
        }

        $registrar = isset($body['registrar']) && $body['registrar'] !== ''
            ? strtolower(trim((string) $body['registrar']))
            : $this->orders->registrarFor($domain);

        try {
            $result = $this->router->renew($domain, $years, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        if (($result['success'] ?? false) !== true) {
            Response::fromRegistrarFailure($result);

            return;
        }

        Response::success($result);
    }

    /**
     * POST /api/v1/domains/transfer
     *
     * @param array{domain?: string, authCode?: string, years?: int, registrar?: string} $body
     */
    public function transfer(array $body): void
    {
        $domain = $this->normalizeDomain((string) ($body['domain'] ?? ''));
        $authCode = trim((string) ($body['authCode'] ?? ''));

        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        if ($authCode === '') {
            Response::error('invalid_argument', '缺少 authCode（转移授权码）', 422);

            return;
        }

        $params = [];
        if (isset($body['years'])) {
            $params['years'] = (int) $body['years'];
        }

        $registrar = isset($body['registrar']) && $body['registrar'] !== ''
            ? strtolower(trim((string) $body['registrar']))
            : null;

        try {
            $result = $this->router->transfer($domain, $authCode, $params, $registrar);
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        if (($result['success'] ?? false) !== true) {
            Response::fromRegistrarFailure($result);

            return;
        }

        Response::success($result);
    }

    /**
     * DELETE /api/v1/domains/{domain}/dns/{id}
     */
    public function deleteDns(string $domain, string $recordId): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        $recordId = trim($recordId);
        if ($recordId === '') {
            Response::error('invalid_argument', '缺少记录 ID', 422);

            return;
        }

        try {
            $result = $this->router->deleteDnsRecord($domain, $recordId, $this->orders->registrarFor($domain));
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        if (($result['success'] ?? false) !== true) {
            Response::fromRegistrarFailure($result, 502);

            return;
        }

        Response::success($result);
    }

    /**
     * GET /api/v1/domains/transfer/{domain}
     */
    public function transferStatus(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        try {
            $status = $this->router->getTransferStatus($domain, $this->orders->registrarFor($domain));
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        Response::success($status);
    }

    /**
     * 供内部与未来端点复用：取 EPP 码。
     */
    public function getEpp(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        if (!$this->isPlausibleDomain($domain)) {
            Response::error('invalid_argument', '域名格式不合法', 422);

            return;
        }

        try {
            $result = $this->router->getEppCode($domain, $this->orders->registrarFor($domain));
        } catch (Throwable $e) {
            Response::error('registrar_unreachable', $e->getMessage(), 502);

            return;
        }

        if (($result['success'] ?? false) !== true) {
            Response::fromRegistrarFailure($result);

            return;
        }

        Response::success($result);
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function extractDomains(array $body): array
    {
        $input = $body['domains'] ?? $body['domain'] ?? [];

        if (is_string($input)) {
            $input = [$input];
        }

        if (!is_array($input)) {
            return [];
        }

        $domains = [];
        foreach ($input as $item) {
            if (!is_string($item)) {
                continue;
            }

            $domain = $this->normalizeDomain($item);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];

        return rtrim($domain, '.');
    }

    private function isPlausibleDomain(string $domain): bool
    {
        return (bool) preg_match(
            '/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $domain
        );
    }
}
