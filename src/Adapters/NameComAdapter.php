<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Adapters;

use Edas\Lite\Contracts\RegistrarAdapterInterface;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Name.com 适配器（Core API v1）。
 *
 * 与 NameSilo 相反，Name.com 是标准 REST：JSON body、HTTP 状态码表达结果、
 * 动作用 `:verb` 后缀表达（如 /domains/example.com:renew）。
 *
 * 关键点：
 *   - 鉴权是 HTTP Basic（username:token），token 不是登录密码；
 *   - 注册与续费必须带 X-Idempotency-Key，否则网络重试会造成重复扣费；
 *   - 错误信息在响应体的 message / details 里，不能只看状态码。
 */
final class NameComAdapter implements RegistrarAdapterInterface
{
    private const DEFAULT_BASE = 'https://api.name.com';

    private const API_PREFIX = '/core/v1';

    private const MESSAGES = [
        400 => 'Name.com 认为请求参数不合法',
        401 => 'Name.com 鉴权失败：用户名或 API Token 无效（Token 不是登录密码）',
        403 => 'Name.com 拒绝访问：该账户无此操作权限',
        404 => 'Name.com 未找到该资源',
        409 => 'Name.com 报告资源冲突（域名已存在或状态不允许当前操作）',
        422 => 'Name.com 无法处理该请求内容',
        429 => 'Name.com 限流：默认 20 请求/秒',
        500 => 'Name.com 内部错误',
        503 => 'Name.com 服务暂不可用',
    ];

    /**
     * @param array{username?: string, token?: string, apiBase?: string, timeout?: int} $config
     */
    public function __construct(
        private array $config,
        private ClientInterface $http
    ) {
        if (($this->config['username'] ?? '') === '' || ($this->config['token'] ?? '') === '') {
            throw new RuntimeException('Name.com 适配器缺少 username 或 token 配置');
        }
    }

    public function getRegistrarName(): string
    {
        return 'namecom';
    }

    // ---------------------------------------------------------------------
    // 契约实现
    // ---------------------------------------------------------------------

    public function checkAvailability(array $domains): array
    {
        $domains = array_values(array_unique(array_map(
            static fn ($d) => strtolower(trim((string) $d)),
            $domains
        )));

        $result = [];
        foreach ($domains as $domain) {
            $result[$domain] = [
                'domain'        => $domain,
                'available'     => false,
                'registerPrice' => null,
                'renewPrice'    => null,
                'currency'      => 'USD',
                'premium'       => false,
                'reason'        => null,
            ];
        }

        if ($domains === []) {
            return [];
        }

        $response = $this->request('POST', '/domains:checkAvailability', [
            'domainNames'  => $domains,
            'purchaseType' => 'registration',
        ]);

        if (!$response['ok']) {
            foreach ($domains as $domain) {
                $result[$domain]['reason'] = $response['message'];
            }

            return $result;
        }

        $items = $response['body']['results'] ?? $response['body']['domains'] ?? [];
        if (!is_array($items)) {
            return $result;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = strtolower(trim((string) ($item['domainName'] ?? '')));
            if ($name === '' || !isset($result[$name])) {
                continue;
            }

            $available = (bool) ($item['available'] ?? false);

            $result[$name] = [
                'domain'        => $name,
                'available'     => $available,
                'registerPrice' => isset($item['purchasePrice']) ? (float) $item['purchasePrice'] : null,
                'renewPrice'    => isset($item['renewalPrice']) ? (float) $item['renewalPrice'] : null,
                'currency'      => 'USD',
                'premium'       => (bool) ($item['premium'] ?? false),
                'reason'        => $available ? null : (isset($item['reason']) ? (string) $item['reason'] : 'unavailable'),
            ];
        }

        return $result;
    }

    public function register(string $domain, int $years, array $params): array
    {
        $payload = [
            'domain' => [
                'domainName'       => $domain,
                'privacyEnabled'   => (bool) ($params['private'] ?? true),
                'autorenewEnabled' => (bool) ($params['autoRenew'] ?? true),
                'locked'           => true,
            ],
            'years'        => $years,
            'purchaseType' => 'registration',
        ];

        if (isset($params['purchasePrice']) && $params['purchasePrice'] !== null) {
            // 溢价域名必须显式传价，且必须与 Get Pricing 的结果完全一致
            $payload['purchasePrice'] = (float) $params['purchasePrice'];
        }

        if (!empty($params['promoCode'])) {
            $payload['promoCode'] = (string) $params['promoCode'];
        }

        if (!empty($params['contacts']) && is_array($params['contacts'])) {
            $payload['domain']['contacts'] = $params['contacts'];
        }

        $response = $this->request(
            'POST',
            '/domains',
            $payload,
            ['X-Idempotency-Key' => $this->idempotencyKey($params)]
        );

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        // 创建成功后确保自动续费与锁定状态符合默认策略（尽力而为，不阻断主流程）
        $this->ensureDefaults($domain);

        $body = $response['body'];
        $createdDomain = is_array($body['domain'] ?? null) ? $body['domain'] : [];

        return [
            'success'            => true,
            'domain'             => strtolower((string) ($createdDomain['domainName'] ?? $domain)),
            'orderId'            => $this->extractOrderId($body),
            'amount'             => isset($body['totalPaid'])
                ? (float) $body['totalPaid']
                : (isset($body['order']['totalPaid']) ? (float) $body['order']['totalPaid'] : null),
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ];
    }

    public function renew(string $domain, int $years): array
    {
        $payload = ['years' => $years];

        $response = $this->request(
            'POST',
            sprintf('/domains/%s:renew', rawurlencode($domain)),
            $payload,
            ['X-Idempotency-Key' => $this->idempotencyKey([])]
        );

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        $body = $response['body'];

        return [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => $this->extractOrderId($body),
            'amount'             => isset($body['totalPaid']) ? (float) $body['totalPaid'] : null,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ];
    }

    public function transfer(string $domain, string $authCode, array $params): array
    {
        $payload = [
            'domainName' => $domain,
            'authCode'   => $authCode,
        ];

        if (isset($params['years'])) {
            $payload['years'] = (int) $params['years'];
        }

        if (!empty($params['contacts']) && is_array($params['contacts'])) {
            $payload['contacts'] = $params['contacts'];
        }

        $response = $this->request(
            'POST',
            '/transfers',
            $payload,
            ['X-Idempotency-Key' => $this->idempotencyKey($params)]
        );

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        $body = $response['body'];

        return [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => $this->extractOrderId($body),
            'amount'             => isset($body['totalPaid']) ? (float) $body['totalPaid'] : null,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ];
    }

    public function getDomainInfo(string $domain): array
    {
        $response = $this->request('GET', sprintf('/domains/%s', rawurlencode($domain)));

        if (!$response['ok']) {
            throw new RuntimeException(sprintf(
                'Name.com getDomainInfo 失败 [%s]: %s',
                (string) ($response['code'] ?? $response['status']),
                $response['message']
            ));
        }

        $body = $response['body'];

        $nameservers = [];
        foreach ((array) ($body['nameservers'] ?? []) as $ns) {
            $value = trim((string) $ns);
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return [
            'domain'      => strtolower((string) ($body['domainName'] ?? $domain)),
            'registrar'   => $this->getRegistrarName(),
            'status'      => strtolower((string) ($body['status'] ?? 'unknown')),
            'expireDate'  => isset($body['expireDate']) ? (string) $body['expireDate'] : null,
            'nameservers' => $nameservers,
            'locked'      => (bool) ($body['locked'] ?? false),
            'autoRenew'   => (bool) ($body['autorenewEnabled'] ?? false),
        ];
    }

    public function getDnsRecords(string $domain): array
    {
        $response = $this->request('GET', sprintf('/domains/%s/records', rawurlencode($domain)));

        if (!$response['ok']) {
            throw new RuntimeException(sprintf(
                'Name.com 读取 DNS 记录失败 [%s]: %s',
                (string) ($response['code'] ?? $response['status']),
                $response['message']
            ));
        }

        $records = [];
        foreach ((array) ($response['body']['records'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $records[] = [
                'id'       => isset($item['id']) ? (string) $item['id'] : null,
                'type'     => strtoupper((string) ($item['type'] ?? '')),
                'host'     => (string) ($item['host'] ?? ''),
                'answer'   => (string) ($item['answer'] ?? ''),
                'ttl'      => (int) ($item['ttl'] ?? 300),
                'priority' => isset($item['priority']) ? (int) $item['priority'] : null,
            ];
        }

        return [
            'domain'  => $domain,
            'records' => $records,
        ];
    }

    /**
     * Name.com 没有整组替换接口，这里同样实现为 upsert：
     * 同 host + type 命中则 PUT /records/{id}，否则 POST /records。
     */
    public function setDnsRecords(string $domain, array $records): array
    {
        $existing = [];
        try {
            foreach ($this->getDnsRecords($domain)['records'] as $record) {
                $key = strtolower($record['host']) . '|' . strtoupper($record['type']);
                $existing[$key] = $record['id'];
            }
        } catch (RuntimeException $e) {
            return [
                'success'            => false,
                'domain'             => $domain,
                'applied'            => 0,
                'warnings'           => [$e->getMessage()],
                'registrarErrorCode' => '210',
                'message'            => '读取现有 DNS 记录失败: ' . $e->getMessage(),
            ];
        }

        $applied = 0;
        $warnings = [];

        foreach ($records as $record) {
            $type = strtoupper(trim((string) ($record['type'] ?? '')));
            $host = trim((string) ($record['host'] ?? ''));
            $answer = trim((string) ($record['answer'] ?? ''));

            if ($type === '' || $answer === '') {
                $warnings[] = '跳过一条缺少 type 或 answer 的记录';

                continue;
            }

            $payload = [
                'host'   => $host,
                'type'   => $type,
                'answer' => $answer,
                'ttl'    => (int) ($record['ttl'] ?? 300),
            ];

            if (isset($record['priority'])) {
                $payload['priority'] = (int) $record['priority'];
            }

            $key = strtolower($host) . '|' . $type;
            $id = $existing[$key] ?? null;

            if ($id !== null) {
                $response = $this->request(
                    'PUT',
                    sprintf('/domains/%s/records/%s', rawurlencode($domain), rawurlencode((string) $id)),
                    $payload
                );
            } else {
                $response = $this->request(
                    'POST',
                    sprintf('/domains/%s/records', rawurlencode($domain)),
                    $payload
                );
            }

            if (!$response['ok']) {
                $warnings[] = sprintf('%s %s: [%s] %s', $type, $host === '' ? '@' : $host, (string) ($response['code'] ?? $response['status']), $response['message']);

                continue;
            }

            $applied++;
        }

        return [
            'success'            => $warnings === [],
            'domain'             => $domain,
            'applied'            => $applied,
            'warnings'           => $warnings,
            'registrarErrorCode' => $warnings === [] ? null : '210',
            'message'            => $warnings === [] ? 'success' : '部分记录写入失败',
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        if (trim($recordId) === '') {
            return [
                'success'            => false,
                'domain'             => $domain,
                'deleted'            => false,
                'registrarErrorCode' => 'invalid_argument',
                'message'            => '缺少 recordId',
            ];
        }

        $response = $this->request(
            'DELETE',
            sprintf('/domains/%s/records/%s', rawurlencode($domain), rawurlencode($recordId))
        );

        return [
            'success'            => $response['ok'],
            'domain'             => $domain,
            'deleted'            => $response['ok'],
            'registrarErrorCode' => $response['ok'] ? null : (string) ($response['code'] ?? $response['status']),
            'message'            => $response['message'],
        ];
    }

    public function getTransferStatus(string $domain): array
    {
        $response = $this->request('GET', sprintf('/transfers/%s', rawurlencode($domain)));

        if (!$response['ok']) {
            return [
                'domain'        => $domain,
                'registrar'     => $this->getRegistrarName(),
                'status'        => $response['status'] === 404 ? 'not_found' : 'unknown',
                'requestedDate' => null,
                'finalDate'     => null,
                'message'       => $response['message'],
            ];
        }

        $body = $response['body'];

        return [
            'domain'        => $domain,
            'registrar'     => $this->getRegistrarName(),
            'status'        => (string) ($body['status'] ?? 'unknown'),
            'requestedDate' => isset($body['requestedDate']) ? (string) $body['requestedDate'] : null,
            'finalDate'     => isset($body['finalDate']) ? (string) $body['finalDate'] : null,
            'message'       => 'success',
        ];
    }

    /**
     * 授权码端点：GET /core/v1/domains/{domainName}:getAuthCode
     */
    public function getEppCode(string $domain): array
    {
        $response = $this->request(
            'GET',
            sprintf('/domains/%s:getAuthCode', rawurlencode($domain))
        );

        if (!$response['ok']) {
            return [
                'success'            => false,
                'domain'             => $domain,
                'authCode'           => null,
                'registrarErrorCode' => (string) ($response['code'] ?? $response['status']),
                'message'            => $response['message'],
            ];
        }

        $body = $response['body'];
        $authCode = (string) ($body['authCode'] ?? $body['auth_code'] ?? '');

        return [
            'success'            => $authCode !== '',
            'domain'             => $domain,
            'authCode'           => $authCode === '' ? null : $authCode,
            'registrarErrorCode' => $authCode === '' ? '210' : null,
            'message'            => $authCode === '' ? 'Name.com 未返回授权码' : 'success',
        ];
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * 统一的 HTTP 调用。不抛业务异常，返回结构化结果供上层判断。
     *
     * @return array{ok: bool, status: int, body: array, code: string|int|null, message: string}
     */
    private function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $base = rtrim((string) ($this->config['apiBase'] ?? self::DEFAULT_BASE), '/');
        $url = $base . self::API_PREFIX . $path;

        $options = [
            'timeout'     => (int) ($this->config['timeout'] ?? 30),
            'http_errors' => false,
            'headers'     => array_merge([
                'Authorization' => 'Basic ' . base64_encode(
                    (string) $this->config['username'] . ':' . (string) $this->config['token']
                ),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ], $headers),
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('Name.com %s %s 请求失败: %s', $method, $path, $e->getMessage()),
                0,
                $e
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        if ($status >= 200 && $status < 300) {
            return [
                'ok'      => true,
                'status'  => $status,
                'body'    => $body,
                'code'    => null,
                'message' => 'success',
            ];
        }

        $message = (string) ($body['message'] ?? (self::MESSAGES[$status] ?? 'Name.com 返回未知错误'));

        $details = $body['details'] ?? null;
        if (is_string($details) && $details !== '') {
            $message .= ' | ' . $details;
        } elseif (is_array($details) && $details !== []) {
            $message .= ' | ' . json_encode($details, JSON_UNESCAPED_UNICODE);
        }

        return [
            'ok'      => false,
            'status'  => $status,
            'body'    => $body,
            'code'    => $body['code'] ?? $status,
            'message' => $message,
        ];
    }

    /**
     * 注册成功后应用默认策略：自动续费开启、域名锁定。
     * 失败不影响主流程，因为域名已经注册成功。
     */
    private function ensureDefaults(string $domain): void
    {
        try {
            $this->request('PATCH', sprintf('/domains/%s', rawurlencode($domain)), [
                'autorenewEnabled' => true,
                'locked'           => true,
            ]);
        } catch (\Throwable) {
            // 忽略：创建已成功，默认策略属于尽力而为
        }
    }

    private function extractOrderId(array $body): ?string
    {
        $candidates = [
            $body['order']['orderId'] ?? null,
            $body['orderId'] ?? null,
            $body['order']['id'] ?? null,
            $body['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function fail(string $domain, array $response): array
    {
        return [
            'success'            => false,
            'domain'             => $domain,
            'orderId'            => null,
            'amount'             => null,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => (string) ($response['code'] ?? $response['status']),
            'message'            => $response['message'],
        ];
    }

    /**
     * 优先使用调用方传入的幂等键；没有则生成 UUID v4。
     */
    private function idempotencyKey(array $params): string
    {
        $provided = trim((string) ($params['idempotencyKey'] ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
