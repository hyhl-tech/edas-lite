<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Http;

/**
 * 统一 JSON 响应。
 *
 * 所有对外响应都是同一个信封，调用方只需要看 success 就能分支：
 *   { "success": true,  "data": {...},  "error": null }
 *   { "success": false, "data": null,   "error": {"code": "...", "message": "...", "details": {...}} }
 */
final class Response
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): void
    {
        $payload = [
            'success' => true,
            'data'    => $data ?? new \stdClass(),
            'error'   => null,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::json($payload, $status);
    }

    /**
     * @param array<string, mixed> $details 附加信息，例如注册商原始错误码
     */
    public static function error(string $code, string $message, int $status = 400, array $details = []): void
    {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        self::json([
            'success' => false,
            'data'    => null,
            'error'   => $error,
        ], $status);
    }

    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($encoded === false) {
            http_response_code(500);
            echo '{"success":false,"data":null,"error":{"code":"encoding_error","message":"响应序列化失败"}}';

            return;
        }

        echo $encoded;
    }

    /**
     * 把注册商适配器返回的业务失败映射为统一错误结构。
     * 注册商原始错误码放在 details.registrarErrorCode，便于调用方对账。
     */
    public static function fromRegistrarFailure(array $result, int $status = 402): void
    {
        self::error(
            'registrar_error',
            (string) ($result['message'] ?? '注册商拒绝了该操作'),
            $status,
            [
                'registrar'          => $result['registrar'] ?? null,
                'registrarErrorCode' => $result['registrarErrorCode'] ?? null,
                'warnings'           => $result['warnings'] ?? [],
            ]
        );
    }
}
