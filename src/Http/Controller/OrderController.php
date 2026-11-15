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
use RuntimeException;
use Throwable;

/**
 * 订单接口。
 *
 * 这里体现「支付外置」的设计：
 *   POST /orders            —— 只落一条待支付订单，不碰注册商
 *   POST /orders/{id}/confirm-payment —— 调用方收到钱之后才触发注册
 */
final class OrderController
{
    public function __construct(private OrderService $orders)
    {
    }

    /**
     * POST /api/v1/orders
     *
     * @param array{domain?: string, years?: int, registrar?: string, private?: bool,
     *              autoRenew?: bool, nameservers?: string[], contacts?: array} $body
     */
    public function create(array $body, ?string $idempotencyKey = null): void
    {
        try {
            $payload = $this->orders->create($body, $idempotencyKey);
        } catch (RuntimeException $e) {
            Response::error('order_create_failed', $e->getMessage(), $e->getCode() === 400 ? 400 : 500);

            return;
        } catch (Throwable $e) {
            Response::error('order_create_failed', $e->getMessage(), 500);

            return;
        }

        Response::success($payload, 201);
    }

    /**
     * POST /api/v1/orders/{id}/confirm-payment
     */
    public function confirmPayment(string $orderId, ?string $idempotencyKey = null, ?string $authorization = null): void
    {
        try {
            $payload = $this->orders->confirmPayment($orderId, $idempotencyKey, $authorization);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            if ($code === 401) {
                Response::error('unauthorized', 'Unauthorized', 401);

                return;
            }

            if ($code === 404) {
                Response::error('order_not_found', $e->getMessage(), 404);

                return;
            }

            Response::error('confirm_payment_failed', $e->getMessage(), $code === 502 ? 502 : 500);

            return;
        } catch (Throwable $e) {
            Response::error('confirm_payment_failed', $e->getMessage(), 500);

            return;
        }

        $result = $payload['result'] ?? [];

        if (($result['success'] ?? false) !== true) {
            // 注册失败：把注册商原始错误码原样交给调用方，订单本身可查
            Response::error(
                'registration_failed',
                (string) ($result['message'] ?? '注册商拒绝了该注册请求'),
                402,
                [
                    'order'              => $payload['order'] ?? null,
                    'registrar'          => $result['registrar'] ?? null,
                    'registrarErrorCode' => $result['registrarErrorCode'] ?? null,
                    'warnings'           => $result['warnings'] ?? [],
                ]
            );

            return;
        }

        Response::success($payload);
    }

    /**
     * GET /api/v1/orders
     *
     * @param array{page?: string|int, perPage?: string|int, domain?: string, status?: string} $query
     */
    public function index(array $query): void
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['perPage'] ?? 20);
        $domain = isset($query['domain']) ? (string) $query['domain'] : null;
        $status = isset($query['status']) ? (string) $query['status'] : null;

        $result = $this->orders->paginate($page, $perPage, $domain, $status);

        Response::success($result, 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'perPage'  => $result['perPage'],
            'lastPage' => $result['lastPage'],
        ]);
    }

    /**
     * DELETE /api/v1/orders/{id}
     */
    public function cancel(string $orderId, ?string $reason = null): void
    {
        try {
            $order = $this->orders->cancel($orderId, $reason);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error(
                $code === 404 ? 'order_not_found' : 'order_not_cancellable',
                $e->getMessage(),
                $code === 404 ? 404 : 409
            );

            return;
        }

        Response::success($order);
    }

    /**
     * GET /api/v1/orders/{id}
     * 未在路由表中暴露，保留给内部与后续版本使用。
     */
    public function show(string $orderId): void
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            Response::error('order_not_found', '订单不存在: ' . $orderId, 404);

            return;
        }

        Response::success($order);
    }
}
