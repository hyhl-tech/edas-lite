<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Order;

use Edas\Lite\Router\RegistrarRouter;
use Edas\Lite\Storage\Database;
use Edas\Lite\Storage\IdempotencyStore;
use RuntimeException;
use Throwable;

/**
 * 订单服务：状态机 + 幂等 + 注册调用。
 *
 * 状态流转：
 *   pending_payment --(confirm-payment)--> processing --> completed
 *                                                      \-> failed
 *
 * 关键约束：
 *   - 本类不处理支付。create() 只落一条待支付订单，不发任何上游注册请求。
 *   - confirmPayment() 才真正调用注册商，且必须幂等：同一订单重复确认只扣一次费。
 */
final class OrderService
{
    public const STATUS_PENDING = 'pending_payment';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private Database $db,
        private IdempotencyStore $idempotency,
        private RegistrarRouter $router,
        private string $appKey
    ) {
    }

    /**
     * 创建订单（待支付）。不发起注册。
     *
     * @param array{domain?: string, years?: int, registrar?: string, private?: bool, autoRenew?: bool,
     *              nameservers?: string[], contacts?: array} $input
     */
    public function create(array $input, ?string $idempotencyKey = null): array
    {
        $domain = $this->normalizeDomain((string) ($input['domain'] ?? ''));
        $years = (int) ($input['years'] ?? 1);

        if ($domain === '') {
            throw new RuntimeException('缺少参数 domain', 400);
        }

        if ($years < 1 || $years > 10) {
            throw new RuntimeException('years 必须是 1 到 10 之间的整数', 400);
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $cached = $this->idempotency->get($idempotencyKey);
            if (is_array($cached) && ($cached['_pending'] ?? false) !== true) {
                return $cached;
            }
        }

        $requestedRegistrar = isset($input['registrar']) && $input['registrar'] !== ''
            ? strtolower(trim((string) $input['registrar']))
            : null;

        $quote = $this->quote($domain, $requestedRegistrar);

        $registrar = $quote['registrar'];
        $amount = $quote['amount'];
        $currency = $quote['currency'];

        $id = 'ord_' . bin2hex(random_bytes(8));
        $now = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO orders (id, domain, years, registrar, status, amount, currency, created_at, updated_at)
             VALUES (:id, :domain, :years, :registrar, :status, :amount, :currency, :created_at, :updated_at)',
            [
                'id'         => $id,
                'domain'     => $domain,
                'years'      => $years,
                'registrar'  => $registrar,
                'status'     => self::STATUS_PENDING,
                'amount'     => $amount,
                'currency'   => $currency,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $payload = [
            'order' => $this->find($id),
            'quote' => $quote['offers'],
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $this->idempotency->remember($idempotencyKey, $payload);
        }

        return $payload;
    }

    /**
     * 确认已收款并触发注册。
     *
     * @param string|null $authorization 原始 Authorization 头，用于二次校验
     */
    public function confirmPayment(string $orderId, ?string $idempotencyKey = null, ?string $authorization = null): array
    {
        $this->assertAuthorized($authorization);

        $order = $this->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在: ' . $orderId, 404);
        }

        // 已完成直接返回首次结果，不再触发任何上游调用
        if ($order['status'] === self::STATUS_COMPLETED) {
            return [
                'order'      => $order,
                'result'     => [
                    'success'    => true,
                    'domain'     => $order['domain'],
                    'registrar'  => $order['registrar'],
                    'orderId'    => $order['registrarOrderId'],
                    'amount'     => $order['amount'],
                    'currency'   => $order['currency'],
                    'idempotent' => true,
                ],
                'idempotent' => true,
            ];
        }

        $key = ($idempotencyKey !== null && $idempotencyKey !== '')
            ? $idempotencyKey
            : 'order:' . $order['id'] . ':confirm';

        $cached = $this->idempotency->get($key);
        if (is_array($cached) && ($cached['_pending'] ?? false) !== true) {
            $cached['idempotent'] = true;

            return $cached;
        }

        $this->updateStatus($order['id'], self::STATUS_PROCESSING, null);

        $params = [
            'idempotencyKey' => $key,
            'private'        => (bool) ($order['private'] ?? true),
        ];

        try {
            $result = $this->router->register(
                $order['domain'],
                (int) $order['years'],
                $params,
                $order['registrar'] !== '' ? $order['registrar'] : null
            );
        } catch (Throwable $e) {
            $this->updateStatus($order['id'], self::STATUS_FAILED, 'exception');
            $this->log(sprintf('订单 %s 注册异常: %s', $order['id'], $e->getMessage()));

            throw new RuntimeException('注册调用失败: ' . $e->getMessage(), 502, $e);
        }

        $success = (bool) ($result['success'] ?? false);

        // 金额与币种以适配器返回为准，缺失时保留下单时的报价
        $amount = isset($result['amount']) && $result['amount'] !== null
            ? (float) $result['amount']
            : (float) $order['amount'];
        $currency = (string) ($result['currency'] ?? $order['currency']);

        if ($success) {
            $this->db->transaction(function (Database $db) use ($order, $result, $amount, $currency): void {
                $db->execute(
                    'UPDATE orders
                        SET status = :status,
                            amount = :amount,
                            currency = :currency,
                            registrar_order_id = :registrar_order_id,
                            error_code = NULL,
                            updated_at = :updated_at
                      WHERE id = :id',
                    [
                        'status'             => self::STATUS_COMPLETED,
                        'amount'             => $amount,
                        'currency'           => $currency,
                        'registrar_order_id' => $result['orderId'] ?? null,
                        'updated_at'         => gmdate('Y-m-d H:i:s'),
                        'id'                 => $order['id'],
                    ]
                );

                $db->execute(
                    'INSERT INTO domain_registrar_map (domain, registrar, created_at)
                     VALUES (:domain, :registrar, :created_at)
                     ON CONFLICT(domain) DO UPDATE SET registrar = excluded.registrar',
                    [
                        'domain'     => $order['domain'],
                        'registrar'  => (string) ($result['registrar'] ?? $order['registrar']),
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]
                );
            });
        } else {
            $this->updateStatus(
                $order['id'],
                self::STATUS_FAILED,
                (string) ($result['registrarErrorCode'] ?? 'unknown')
            );
        }

        $payload = [
            'order'  => $this->find($order['id']),
            'result' => $result,
            'idempotent' => false,
        ];

        $this->idempotency->remember($key, $payload);

        return $payload;
    }

    public function find(string $orderId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * 读取某域名的归属注册商，用于查询路由。
     */
    public function registrarFor(string $domain): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT registrar FROM domain_registrar_map WHERE domain = :domain',
            ['domain' => $this->normalizeDomain($domain)]
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * 列出订单，可按域名过滤。
     *
     * @return array<int, array>
     */
    public function list(int $limit = 50, ?string $domain = null): array
    {
        $limit = max(1, min(500, $limit));

        if ($domain !== null && $domain !== '') {
            $rows = $this->db->fetchAll(
                'SELECT * FROM orders WHERE domain = :domain ORDER BY created_at DESC LIMIT ' . $limit,
                ['domain' => $this->normalizeDomain($domain)]
            );
        } else {
            $rows = $this->db->fetchAll('SELECT * FROM orders ORDER BY created_at DESC LIMIT ' . $limit);
        }

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    /**
     * 取消订单。已完成的订单不可取消（域名已经注册出去了）。
     */
    public function cancel(string $orderId, ?string $reason = null): array
    {
        $order = $this->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在: ' . $orderId, 404);
        }

        if ($order['status'] === self::STATUS_COMPLETED) {
            throw new RuntimeException('订单已完成，无法取消；如需退款请走注册商流程', 409);
        }

        if ($order['status'] === 'cancelled') {
            return $order;
        }

        $this->db->execute(
            'UPDATE orders SET status = :status, error_code = :error_code, updated_at = :updated_at WHERE id = :id',
            [
                'status'     => 'cancelled',
                'error_code' => $reason !== null && $reason !== '' ? substr($reason, 0, 120) : 'cancelled_by_caller',
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id'         => $orderId,
            ]
        );

        return $this->find($orderId) ?? $order;
    }

    /**
     * 分页查询订单。
     *
     * @return array{items: array<int, array>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 20, ?string $domain = null, ?string $status = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($domain !== null && $domain !== '') {
            $where[] = 'domain = :domain';
            $params['domain'] = $this->normalizeDomain($domain);
        }

        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $clause = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders' . $clause, $params);

        $rows = $this->db->fetchAll(
            'SELECT * FROM orders' . $clause . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items'    => array_map(fn (array $row): array => $this->hydrate($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
            'lastPage' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * 把超时未支付的订单标记为 expired，避免状态机里堆积僵尸订单。
     *
     * @return int 被更新的订单数
     */
    public function expireStale(int $minutes = 60): int
    {
        $minutes = max(1, $minutes);

        return $this->db->execute(
            "UPDATE orders
                SET status = 'expired', updated_at = datetime('now')
              WHERE status = :pending
                AND created_at < datetime('now', :modifier)",
            [
                'pending'  => self::STATUS_PENDING,
                'modifier' => sprintf('-%d minutes', $minutes),
            ]
        );
    }

    /**
     * 按域名查询最近一条订单，便于外部对账。
     */
    public function latestForDomain(string $domain): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM orders WHERE domain = :domain ORDER BY created_at DESC LIMIT 1',
            ['domain' => $this->normalizeDomain($domain)]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * 下单时的报价（尽力而为）。失败不影响下单，只是金额留 0。
     *
     * @return array{registrar: string, amount: float, currency: string, offers: array}
     */
    private function quote(string $domain, ?string $registrar): array
    {
        $fallbackRegistrar = $registrar ?? ($this->router->available()[0] ?? 'namesilo');

        try {
            $quotes = $this->router->checkAvailability([$domain], $registrar);
        } catch (Throwable $e) {
            $this->log(sprintf('域名 %s 报价失败，订单金额置 0: %s', $domain, $e->getMessage()));

            return [
                'registrar' => $fallbackRegistrar,
                'amount'    => 0.0,
                'currency'  => 'USD',
                'offers'    => [],
            ];
        }

        if ($quotes === []) {
            return [
                'registrar' => $fallbackRegistrar,
                'amount'    => 0.0,
                'currency'  => 'USD',
                'offers'    => [],
            ];
        }

        $entry = $quotes[0];
        $offers = $entry['offers'];

        foreach ($offers as $offer) {
            if ($registrar !== null && $offer['registrar'] === $registrar) {
                return [
                    'registrar' => $registrar,
                    'amount'    => $offer['registerPrice'] !== null ? (float) $offer['registerPrice'] : 0.0,
                    'currency'  => $offer['currency'],
                    'offers'    => $offers,
                ];
            }
        }

        $chosen = $offers[0] ?? null;

        return [
            'registrar' => $chosen['registrar'] ?? $fallbackRegistrar,
            'amount'    => isset($chosen['registerPrice']) && $chosen['registerPrice'] !== null
                ? (float) $chosen['registerPrice']
                : 0.0,
            'currency'  => $chosen['currency'] ?? 'USD',
            'offers'    => $offers,
        ];
    }

    private function updateStatus(string $orderId, string $status, ?string $errorCode): void
    {
        $this->db->execute(
            'UPDATE orders SET status = :status, error_code = :error_code, updated_at = :updated_at WHERE id = :id',
            [
                'status'     => $status,
                'error_code' => $errorCode,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id'         => $orderId,
            ]
        );
    }

    private function hydrate(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'domain'           => (string) $row['domain'],
            'years'            => (int) $row['years'],
            'registrar'        => (string) $row['registrar'],
            'status'           => (string) $row['status'],
            'amount'           => (float) $row['amount'],
            'currency'         => (string) $row['currency'],
            'registrarOrderId' => $row['registrar_order_id'] !== null ? (string) $row['registrar_order_id'] : null,
            'errorCode'        => $row['error_code'] !== null ? (string) $row['error_code'] : null,
            'createdAt'        => (string) $row['created_at'],
            'updatedAt'        => (string) $row['updated_at'],
        ];
    }

    /**
     * confirmPayment 自身再校验一次 Bearer，作为路由中间件之外的纵深防御。
     */
    private function assertAuthorized(?string $authorization): void
    {
        if ($this->appKey === '') {
            throw new RuntimeException('服务未配置 APP_KEY，拒绝执行资金相关操作', 500);
        }

        if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
            throw new RuntimeException('Unauthorized', 401);
        }

        if (!hash_equals($this->appKey, trim($m[1]))) {
            throw new RuntimeException('Unauthorized', 401);
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        // 去掉协议、路径与末尾的点，容忍调用方传入 URL
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];

        return rtrim($domain, '.');
    }

    private function log(string $message): void
    {
        error_log('[edas-lite][order] ' . $message);
    }
}
