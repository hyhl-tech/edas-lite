<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Router;

use Edas\Lite\Contracts\RegistrarAdapterInterface;
use RuntimeException;
use Throwable;

/**
 * 注册商路由器。
 *
 * 职责边界：
 *   1. 按调用方显式指定的 registrar 选适配器；
 *   2. 未指定时按 REGISTRAR_PRIORITY 顺序尝试；
 *   3. 某个适配器抛异常（配置错误、网络不可用、未实现）时记录日志并换下一家；
 *   4. 业务失败（success=false）不重试——换一家注册商重试注册是危险行为，
 *      可能造成重复扣费或域名抢注纠纷；
 *   5. 全部适配器都不可用时抛出明确异常，附带每家的失败原因。
 *
 * 价格排序（TLD 智能比价）是可选项，由 SMART_PRICE_ROUTING 控制，
 * 默认关闭；关闭时本类不做任何排序，纯粹按优先级走。
 */
final class RegistrarRouter
{
    /** @var array<string, RegistrarAdapterInterface> */
    private array $adapters = [];

    /** @var string[] 已解析出的可用顺序 */
    private array $order = [];

    /** 上一次成功调用所使用的注册商 */
    private ?string $lastUsed = null;

    /**
     * @param array<string, RegistrarAdapterInterface> $adapters 以注册商标识为键
     * @param string[]                                 $priority 优先级顺序
     * @param bool                                     $smartPricing 是否启用低价优先
     */
    public function __construct(
        array $adapters,
        array $priority,
        private bool $smartPricing = false
    ) {
        foreach ($adapters as $name => $adapter) {
            $this->adapters[strtolower((string) $name)] = $adapter;
        }

        $configured = [];
        foreach ($priority as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }

            if (!isset($this->adapters[$name])) {
                $this->log(sprintf(
                    'REGISTRAR_PRIORITY 中列出的 "%s" 没有对应适配器（缺少凭证或未实现），已跳过',
                    $name
                ));

                continue;
            }

            if (!in_array($name, $configured, true)) {
                $configured[] = $name;
            }
        }

        // 有适配器但优先级里没写，追加到末尾，避免悄悄丢掉配置
        foreach (array_keys($this->adapters) as $name) {
            if (!in_array($name, $configured, true)) {
                $configured[] = $name;
            }
        }

        if ($configured === []) {
            throw new RuntimeException('没有任何可用的注册商适配器，请检查 .env 中的凭证与 REGISTRAR_PRIORITY');
        }

        $this->order = $configured;
    }

    /**
     * @return string[] 有序的可用注册商
     */
    public function available(): array
    {
        return $this->order;
    }

    public function has(string $registrar): bool
    {
        return isset($this->adapters[strtolower($registrar)]);
    }

    public function adapter(string $registrar): RegistrarAdapterInterface
    {
        $name = strtolower($registrar);
        if (!isset($this->adapters[$name])) {
            throw new RuntimeException(sprintf('未知的注册商标识: %s', $registrar));
        }

        return $this->adapters[$name];
    }

    // ---------------------------------------------------------------------
    // 查询类
    // ---------------------------------------------------------------------

    /**
     * 汇总可用性与报价。
     *
     * @param string[] $domains
     * @return array<int, array{
     *     domain: string,
     *     available: bool,
     *     offers: array<int, array{
     *         registrar: string,
     *         registerPrice: float|null,
     *         renewPrice: float|null,
     *         currency: string,
     *         available: bool,
     *         premium: bool,
     *         reason: string|null
     *     }>
     * }>
     */
    public function checkAvailability(array $domains, ?string $registrar = null): array
    {
        $domains = array_values(array_filter(array_map(
            static fn ($d) => strtolower(trim((string) $d)),
            $domains
        ), static fn ($d) => $d !== ''));

        $targets = $registrar !== null ? [$this->resolveExplicit($registrar)] : $this->order;

        $aggregate = [];
        foreach ($domains as $domain) {
            $aggregate[$domain] = [
                'domain'    => $domain,
                'available' => false,
                'offers'    => [],
            ];
        }

        $failures = [];

        foreach ($targets as $name) {
            if (!isset($this->adapters[$name])) {
                $failures[] = sprintf('%s: 未配置', $name);

                continue;
            }

            try {
                $result = $this->adapters[$name]->checkAvailability($domains);
            } catch (Throwable $e) {
                $this->log(sprintf('[%s] 可用性查询失败: %s', $name, $e->getMessage()));
                $failures[] = sprintf('%s: %s', $name, $e->getMessage());

                continue;
            }

            foreach ($domains as $domain) {
                $item = $result[$domain] ?? null;
                if (!is_array($item)) {
                    continue;
                }

                $aggregate[$domain]['offers'][] = [
                    'registrar'     => $name,
                    'registerPrice' => $item['registerPrice'] ?? null,
                    'renewPrice'    => $item['renewPrice'] ?? null,
                    'currency'      => $item['currency'] ?? 'USD',
                    'available'     => (bool) ($item['available'] ?? false),
                    'premium'       => (bool) ($item['premium'] ?? false),
                    'reason'        => $item['reason'] ?? null,
                ];
            }
        }

        foreach ($aggregate as $domain => $entry) {
            $offers = $entry['offers'];

            if ($this->smartPricing) {
                usort($offers, static function (array $a, array $b): int {
                    if ($a['available'] !== $b['available']) {
                        return $a['available'] ? -1 : 1;
                    }

                    $pa = $a['registerPrice'];
                    $pb = $b['registerPrice'];

                    if ($pa === null && $pb === null) {
                        return 0;
                    }

                    if ($pa === null) {
                        return 1;
                    }

                    if ($pb === null) {
                        return -1;
                    }

                    return $pa <=> $pb;
                });
            }

            $aggregate[$domain]['offers'] = $offers;
            $aggregate[$domain]['available'] = (bool) array_reduce(
                $offers,
                static fn (bool $carry, array $offer): bool => $carry || $offer['available'],
                false
            );
        }

        if ($aggregate === [] && $failures !== []) {
            throw new RuntimeException('全部注册商查询失败: ' . implode('; ', $failures));
        }

        return array_values($aggregate);
    }

    public function getDomainInfo(string $domain, ?string $registrar = null): array
    {
        return $this->attempt(
            'getDomainInfo',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->getDomainInfo($domain)
        );
    }

    public function getDnsRecords(string $domain, ?string $registrar = null): array
    {
        return $this->attempt(
            'getDnsRecords',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->getDnsRecords($domain)
        );
    }

    public function getEppCode(string $domain, ?string $registrar = null): array
    {
        return $this->attempt(
            'getEppCode',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->getEppCode($domain)
        );
    }

    // ---------------------------------------------------------------------
    // 写操作
    // ---------------------------------------------------------------------

    public function register(string $domain, int $years, array $params = [], ?string $registrar = null): array
    {
        $result = $this->attempt(
            'register',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->register($domain, $years, $params),
            $domain
        );

        $result['registrar'] = $result['registrar'] ?? $this->lastUsed;

        return $result;
    }

    public function renew(string $domain, int $years, ?string $registrar = null): array
    {
        $result = $this->attempt(
            'renew',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->renew($domain, $years),
            $domain
        );

        $result['registrar'] = $result['registrar'] ?? $this->lastUsed;

        return $result;
    }

    public function transfer(string $domain, string $authCode, array $params = [], ?string $registrar = null): array
    {
        $result = $this->attempt(
            'transfer',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->transfer($domain, $authCode, $params),
            $domain
        );

        $result['registrar'] = $result['registrar'] ?? $this->lastUsed;

        return $result;
    }

    public function setDnsRecords(string $domain, array $records, ?string $registrar = null): array
    {
        return $this->attempt(
            'setDnsRecords',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->setDnsRecords($domain, $records)
        );
    }

    public function deleteDnsRecord(string $domain, string $recordId, ?string $registrar = null): array
    {
        return $this->attempt(
            'deleteDnsRecord',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->deleteDnsRecord($domain, $recordId)
        );
    }

    public function getTransferStatus(string $domain, ?string $registrar = null): array
    {
        return $this->attempt(
            'getTransferStatus',
            $registrar,
            static fn (RegistrarAdapterInterface $adapter): array => $adapter->getTransferStatus($domain)
        );
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * 按顺序尝试适配器。仅在适配器抛异常时切换下一家；
     * 返回数组（含 success=false）视为业务结论，立刻返回，不再换家。
     *
     * @param callable(RegistrarAdapterInterface):array $operation
     */
    private function attempt(string $name, ?string $registrar, callable $operation, ?string $domain = null): array
    {
        $candidates = $registrar !== null
            ? [$this->resolveExplicit($registrar)]
            : $this->orderedCandidates($domain, $name);

        $failures = [];

        foreach ($candidates as $candidate) {
            try {
                $result = $operation($this->adapters[$candidate]);
                $this->lastUsed = $candidate;

                return $result;
            } catch (Throwable $e) {
                $this->log(sprintf('[%s] %s 失败: %s', $candidate, $name, $e->getMessage()));
                $failures[] = sprintf('%s: %s', $candidate, $e->getMessage());
            }
        }

        throw new RuntimeException(sprintf(
            '%s 在所有注册商上均失败: %s',
            $name,
            implode('; ', $failures)
        ));
    }

    /**
     * 显式指定时必须存在，否则直接报错——不能悄悄换一家。
     */
    private function resolveExplicit(string $registrar): string
    {
        $name = strtolower(trim($registrar));

        if (!isset($this->adapters[$name])) {
            throw new RuntimeException(sprintf(
                '指定了注册商 "%s"，但它没有可用适配器（缺少凭证、未在 REGISTRAR_PRIORITY 中，或尚未实现）',
                $registrar
            ));
        }

        return $name;
    }

    /**
     * 未显式指定时的候选顺序。启用智能比价且是查询/注册类操作时，
     * 按报价从低到高重排；否则原样返回优先级顺序。
     *
     * @return string[]
     */
    private function orderedCandidates(?string $domain, string $operation): array
    {
        if (!$this->smartPricing || $domain === null) {
            return $this->order;
        }

        if (!in_array($operation, ['register', 'transfer'], true)) {
            return $this->order;
        }

        try {
            $quotes = $this->checkAvailability([$domain]);
        } catch (Throwable) {
            return $this->order;
        }

        if ($quotes === []) {
            return $this->order;
        }

        $prices = [];
        foreach ($quotes[0]['offers'] as $offer) {
            if (($offer['available'] ?? false) === true && $offer['registerPrice'] !== null) {
                $prices[$offer['registrar']] = (float) $offer['registerPrice'];
            }
        }

        if ($prices === []) {
            return $this->order;
        }

        $sorted = $this->order;
        usort($sorted, static function (string $a, string $b) use ($prices): int {
            $pa = $prices[$a] ?? PHP_FLOAT_MAX;
            $pb = $prices[$b] ?? PHP_FLOAT_MAX;

            return $pa <=> $pb;
        });

        $this->log(sprintf('智能比价已启用，%s 的候选顺序: %s', $domain, implode(',', $sorted)));

        return $sorted;
    }

    private function log(string $message): void
    {
        error_log('[edas-lite][router] ' . $message);
    }
}
