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
use Edas\Lite\Exceptions\NotImplementedException;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * DomainNameAPI（DNA / Domain Reseller API）适配器。
 *
 * 与另两家最大的不同：
 *   - 认证用两个头：X-API-KEY + __reseller（GUID），既不是查询参数也不是 Basic；
 *   - 认证失败返回「纯文本 + 401」，不是 JSON，解析前必须先判断响应体能否解码；
 *   - 业务错误封装在 {error:{code,message,details}}，code 形如 Service:Area:Number，
 *     不能按 HTTP 状态码分支；
 *   - 单域名查询走 search（1 积分），多域名走 bulk-search（10 积分），必须按需选择。
 *
 * DNS 记录与 DNSSEC 端点尚未在已确认的官方文档中给出，故抛 NotImplementedException，
 * 而不是静默返回空数据。
 */
final class DomainNameApiAdapter implements RegistrarAdapterInterface
{
    private const DEFAULT_BASE = 'https://api.domainresellerapi.com';

    private const API_PREFIX = '/api/v1';

    /** bulk-search 单次上限，官方文档明确为 30 */
    private const BULK_LIMIT = 30;

    /**
     * @param array{resellerId?: string, apiKey?: string, apiBase?: string, timeout?: int} $config
     */
    public function __construct(
        private array $config,
        private ClientInterface $http
    ) {
        if (($this->config['resellerId'] ?? '') === '' || ($this->config['apiKey'] ?? '') === '') {
            throw new RuntimeException('DomainNameAPI 适配器缺少 resellerId 或 apiKey 配置');
        }
    }

    public function getRegistrarName(): string
    {
        return 'domainnameapi';
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
            $result[$domain] = $this->stub($domain);
        }

        if ($domains === []) {
            return $result;
        }

        // 单个域名走 search（1 积分），多个才走 bulk-search（10 积分）
        if (count($domains) === 1) {
            $domain = $domains[0];
            $response = $this->request('POST', '/domains/search', ['domainName' => $domain]);

            if (!$response['ok']) {
                $result[$domain]['reason'] = $response['message'];

                return $result;
            }

            return [$domain => $this->mapInfo($domain, $this->extractInfo($response['body']))];
        }

        foreach (array_chunk($domains, self::BULK_LIMIT) as $chunk) {
            $payload = array_map(static fn ($d) => ['domainName' => $d], $chunk);

            $response = $this->request('POST', '/domains/bulk-search', $payload);

            if (!$response['ok']) {
                foreach ($chunk as $domain) {
                    $result[$domain]['reason'] = $response['message'];
                }

                continue;
            }

            $infos = $response['body']['infos'] ?? [];
            if (!is_array($infos)) {
                continue;
            }

            // 结果可能少于请求数，必须按 domainName 匹配而不是按数组下标
            foreach ($infos as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $info = isset($item['info']) && is_array($item['info']) ? $item['info'] : $item;
                $name = strtolower(trim((string) ($info['domainName'] ?? '')));

                if ($name === '' || !isset($result[$name])) {
                    continue;
                }

                $result[$name] = $this->mapInfo($name, $info);
            }
        }

        return $result;
    }

    public function register(string $domain, int $years, array $params): array
    {
        $payload = [
            'domainName'        => $domain,
            'period'            => $years,
            'nameServers'       => array_values(array_filter(array_map(
                static fn ($ns) => trim((string) $ns),
                (array) ($params['nameservers'] ?? [])
            ))),
            'contacts'          => $this->buildContacts($params),
            'tldAttributes'     => (object) ((array) ($params['tldAttributes'] ?? [])),
            'useTrusteeContact' => (bool) ($params['useTrusteeContact'] ?? false),
        ];

        // DNA 允许 1~4 个联系人，缺的由平台自动补齐，因此空值直接不传
        if ($payload['contacts'] === []) {
            unset($payload['contacts']);
        }

        if ($payload['nameServers'] === []) {
            unset($payload['nameServers']);
        }

        $response = $this->request('POST', '/domains/register-with-contacts', $payload);

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        $body = $response['body'];
        $success = (bool) ($body['success'] ?? true);
        $status = (string) ($body['status'] ?? '');

        return [
            'success'            => $success,
            'domain'             => $domain,
            'orderId'            => isset($body['domainId']) ? (string) $body['domainId'] : null,
            'amount'             => isset($body['price']) ? (float) $body['price'] : null,
            'currency'           => (string) ($body['currency'] ?? 'USD'),
            'partialSuccess'     => false,
            'warnings'           => $status !== '' && strtolower($status) !== 'active'
                ? [sprintf('注册已受理，当前状态: %s', $status)]
                : [],
            'registrarErrorCode' => $success ? null : (string) ($body['code'] ?? 'unknown'),
            'message'            => (string) ($body['message'] ?? ($success ? 'success' : '注册失败')),
        ];
    }

    public function renew(string $domain, int $years): array
    {
        // 先做免费预检，避免直接扣费失败
        $check = $this->request('POST', '/domains/renew/check', [
            'domainName' => $domain,
            'period'     => $years,
        ]);

        if (!$check['ok']) {
            return $this->fail($domain, $check);
        }

        $checkBody = $check['body'];

        if ($this->isNegativeRenewability($checkBody['renewabilityStatus'] ?? null)) {
            return [
                'success'            => false,
                'domain'             => $domain,
                'orderId'            => null,
                'amount'             => null,
                'currency'           => 'USD',
                'partialSuccess'     => false,
                'warnings'           => [],
                'registrarErrorCode' => (string) ($checkBody['errorMessageKey'] ?? 'not_renewable'),
                'message'            => sprintf(
                    '当前不可续费: %s',
                    (string) ($checkBody['errorMessageKey'] ?? 'renewabilityStatus=false')
                ),
            ];
        }

        $response = $this->request('POST', '/domains/renew', [
            'domainName' => $domain,
            'period'     => $years,
        ]);

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        $body = $response['body'];
        $success = (bool) ($body['success'] ?? true);

        return [
            'success'            => $success,
            'domain'             => $domain,
            'orderId'            => isset($body['domainId']) ? (string) $body['domainId'] : null,
            'amount'             => isset($checkBody['premiumPrice']) && $checkBody['premiumPrice'] !== null
                ? (float) $checkBody['premiumPrice']
                : (isset($body['price']) ? (float) $body['price'] : null),
            'currency'           => (string) ($body['currency'] ?? $checkBody['premiumCurrency'] ?? 'USD'),
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => $success ? null : (string) ($body['code'] ?? 'unknown'),
            'message'            => (string) ($body['message'] ?? ($success ? 'success' : '续费失败')),
        ];
    }

    public function transfer(string $domain, string $authCode, array $params): array
    {
        $check = $this->request('POST', '/domains/transfers/check', [
            'domainName' => $domain,
            'authCode'   => $authCode,
        ]);

        if (!$check['ok']) {
            return $this->fail($domain, $check);
        }

        $checkBody = $check['body'];

        if (($checkBody['authCodeIsValid'] ?? true) === false) {
            return [
                'success'            => false,
                'domain'             => $domain,
                'orderId'            => null,
                'amount'             => null,
                'currency'           => 'USD',
                'partialSuccess'     => false,
                'warnings'           => [],
                'registrarErrorCode' => 'invalid_auth_code',
                'message'            => '转移授权码无效',
            ];
        }

        $payload = [
            'domainName' => $domain,
            'authCode'   => $authCode,
        ];

        if (isset($params['years'])) {
            $payload['period'] = (int) $params['years'];
        }

        $response = $this->request('POST', '/domains/transfer', $payload);

        if (!$response['ok']) {
            return $this->fail($domain, $response);
        }

        $body = $response['body'];
        $success = (bool) ($body['success'] ?? true);
        $pending = strtolower((string) ($body['status'] ?? '')) === 'pending';

        return [
            'success'            => $success,
            'domain'             => $domain,
            'orderId'            => isset($body['domainId']) ? (string) $body['domainId'] : null,
            'amount'             => isset($checkBody['premiumPrice']) && $checkBody['premiumPrice'] !== null
                ? (float) $checkBody['premiumPrice']
                : (isset($body['price']) ? (float) $body['price'] : null),
            'currency'           => (string) ($body['currency'] ?? 'USD'),
            'partialSuccess'     => $pending,
            'warnings'           => $pending
                ? ['转入已提交，等待当前注册商放行（注册局最长 5 天自动批准）']
                : [],
            'registrarErrorCode' => $success ? null : (string) ($body['code'] ?? 'unknown'),
            'message'            => (string) ($body['message'] ?? ($success ? 'success' : '转入失败')),
        ];
    }

    public function getDomainInfo(string $domain): array
    {
        $response = $this->request('GET', '/domains/info?domainName=' . rawurlencode($domain));

        if (!$response['ok']) {
            throw new RuntimeException(sprintf(
                'DomainNameAPI getDomainInfo 失败 [%s]: %s',
                (string) ($response['code'] ?? $response['status']),
                $response['message']
            ));
        }

        $data = $this->extractInfo($response['body']);

        $nameservers = [];
        foreach ((array) ($data['nameservers'] ?? []) as $ns) {
            if (is_array($ns)) {
                $value = (string) ($ns['hostName'] ?? $ns['hostname'] ?? $ns['name'] ?? '');
            } else {
                $value = (string) $ns;
            }

            $value = trim($value);
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return [
            'domain'      => strtolower((string) ($data['domainName'] ?? $domain)),
            'registrar'   => $this->getRegistrarName(),
            'status'      => strtolower((string) ($data['status'] ?? 'unknown')),
            'expireDate'  => isset($data['expirationDate'])
                ? (string) $data['expirationDate']
                : (isset($data['expiryDate']) ? (string) $data['expiryDate'] : null),
            'nameservers' => $nameservers,
            'locked'      => $this->isLocked($data['lockStatus'] ?? $data['lockStatusName'] ?? null),
            'autoRenew'   => (int) ($data['renewalMode'] ?? 0) === 1,
        ];
    }

    /**
     * DNS 记录端点未在已确认的官方文档中出现，明确抛出而不是返回空数组。
     */
    public function getDnsRecords(string $domain): array
    {
        throw NotImplementedException::forAdapter($this->getRegistrarName(), __FUNCTION__);
    }

    public function setDnsRecords(string $domain, array $records): array
    {
        throw NotImplementedException::forAdapter($this->getRegistrarName(), __FUNCTION__);
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        throw NotImplementedException::forAdapter($this->getRegistrarName(), __FUNCTION__);
    }

    /**
     * DNA 的 /domains/info 响应本身包含 authCode 字段，直接复用，不再单独请求。
     */
    public function getEppCode(string $domain): array
    {
        $response = $this->request('GET', '/domains/info?domainName=' . rawurlencode($domain));

        if (!$response['ok']) {
            return [
                'success'            => false,
                'domain'             => $domain,
                'authCode'           => null,
                'registrarErrorCode' => (string) ($response['code'] ?? $response['status']),
                'message'            => $response['message'],
            ];
        }

        $data = $this->extractInfo($response['body']);
        $authCode = trim((string) ($data['authCode'] ?? ''));

        return [
            'success'            => $authCode !== '',
            'domain'             => $domain,
            'authCode'           => $authCode === '' ? null : $authCode,
            'registrarErrorCode' => $authCode === '' ? 'not_available' : null,
            'message'            => $authCode === '' ? 'DNA 未在域名信息中返回 authCode' : 'success',
        ];
    }

    public function getTransferStatus(string $domain): array
    {
        $response = $this->request('POST', '/domains/transfers/query', ['domainName' => $domain]);

        if (!$response['ok']) {
            return [
                'domain'        => $domain,
                'registrar'     => $this->getRegistrarName(),
                'status'        => 'unknown',
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
     * 供健康检查使用：凭证是否齐备（不发起请求）。
     */
    public function isConfigured(): bool
    {
        return ($this->config['resellerId'] ?? '') !== '' && ($this->config['apiKey'] ?? '') !== '';
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * @return array{ok: bool, status: int, body: array, code: string|int|null, message: string}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $base = rtrim((string) ($this->config['apiBase'] ?? self::DEFAULT_BASE), '/');
        $url = $base . self::API_PREFIX . $path;

        $options = [
            'timeout'     => (int) ($this->config['timeout'] ?? 30),
            'http_errors' => false,
            'headers'     => [
                'X-API-KEY'        => (string) $this->config['apiKey'],
                '__reseller'       => (string) $this->config['resellerId'],
                'X-Correlation-ID' => bin2hex(random_bytes(8)),
                'Accept'           => 'application/json',
                'Content-Type'     => 'application/json',
            ],
        ];

        if ($json !== null) {
            // bulk-search 的 body 是顶层 JSON 数组，必须显式编码才能保持数组形态
            $options['body'] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('DomainNameAPI %s %s 请求失败: %s', $method, $path, $e->getMessage()),
                0,
                $e
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);

        // 401 时 DNA 返回纯文本，例如 "Unauthorized! API Key is missing."
        if (!is_array($body)) {
            return [
                'ok'      => false,
                'status'  => $status,
                'body'    => [],
                'code'    => $status,
                'message' => trim($raw) !== '' ? trim($raw) : 'DomainNameAPI 返回了非 JSON 响应',
            ];
        }

        if ($status >= 200 && $status < 300) {
            if (($body['success'] ?? true) === false) {
                return [
                    'ok'      => false,
                    'status'  => $status,
                    'body'    => $body,
                    'code'    => $body['code'] ?? $status,
                    'message' => (string) ($body['reason'] ?? $body['message'] ?? 'DomainNameAPI 报告业务失败'),
                ];
            }

            return [
                'ok'      => true,
                'status'  => $status,
                'body'    => $body,
                'code'    => null,
                'message' => 'success',
            ];
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = (string) ($error['message'] ?? sprintf('DomainNameAPI 返回 HTTP %d', $status));

        if (isset($error['details']) && is_array($error['details']) && $error['details'] !== []) {
            $message .= ' | ' . json_encode($error['details'], JSON_UNESCAPED_UNICODE);
        }

        return [
            'ok'      => false,
            'status'  => $status,
            'body'    => $body,
            'code'    => $error['code'] ?? $status,
            'message' => $message,
        ];
    }

    /**
     * 兼容 {info:{...}}、{domain:{...}}、{data:{...}} 与裸对象四种包裹方式。
     */
    private function extractInfo(array $body): array
    {
        foreach (['info', 'domain', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return $body[$key];
            }
        }

        return $body;
    }

    private function mapInfo(string $domain, array $info): array
    {
        $status = strtoupper((string) ($info['status'] ?? 'NONE'));
        $available = $status === 'AVAILABLE';

        return [
            'domain'        => $domain,
            'available'     => $available,
            'registerPrice' => isset($info['price']) ? (float) $info['price'] : null,
            'renewPrice'    => isset($info['renewPrice']) ? (float) $info['renewPrice'] : null,
            'currency'      => (string) ($info['currency'] ?? 'USD'),
            'premium'       => (bool) ($info['isPremium'] ?? false),
            'reason'        => $available ? null : strtolower($status),
        ];
    }

    private function stub(string $domain): array
    {
        return [
            'domain'        => $domain,
            'available'     => false,
            'registerPrice' => null,
            'renewPrice'    => null,
            'currency'      => 'USD',
            'premium'       => false,
            'reason'        => null,
        ];
    }

    /**
     * 把统一联系人结构翻译成 DNA 字段名（注意 eMail 的大小写）。
     *
     * @return array<int, array<string, string>>
     */
    private function buildContacts(array $params): array
    {
        $contact = $params['contacts'] ?? null;
        if (!is_array($contact) || $contact === []) {
            return [];
        }

        if (isset($contact['contactType'])) {
            $contacts = [$contact];
        } elseif (isset($contact[0]) && is_array($contact[0])) {
            $contacts = $contact;
        } else {
            $contacts = [$contact + ['contactType' => 'Registrant']];
        }

        $result = [];
        foreach ($contacts as $item) {
            if (!is_array($item)) {
                continue;
            }

            [$countryCode, $national] = $this->splitPhone(
                (string) ($item['phone'] ?? ''),
                (string) ($item['phoneCountryCode'] ?? '')
            );

            $mapped = array_filter([
                'contactType'      => (string) ($item['contactType'] ?? 'Registrant'),
                'firstName'        => (string) ($item['firstName'] ?? ''),
                'lastName'         => (string) ($item['lastName'] ?? ''),
                'companyName'      => (string) ($item['companyName'] ?? $item['company'] ?? ''),
                'eMail'            => (string) ($item['eMail'] ?? $item['email'] ?? ''),
                'address'          => (string) ($item['address'] ?? $item['address1'] ?? ''),
                'city'             => (string) ($item['city'] ?? ''),
                'state'            => (string) ($item['state'] ?? ''),
                'postalCode'       => (string) ($item['postalCode'] ?? $item['zip'] ?? ''),
                'country'          => strtoupper((string) ($item['country'] ?? '')),
                'phoneCountryCode' => $countryCode,
                'phone'            => $national,
            ], static fn (string $value): bool => $value !== '');

            $result[] = $mapped;
        }

        return $result;
    }

    /**
     * 拆分 E.164 号码为「国家码」与「国内号码」（DNA 要求国家码为纯数字、不带 +）。
     *
     * @return array{0: string, 1: string}
     */
    private function splitPhone(string $phone, string $explicitCountryCode): array
    {
        $explicit = ltrim(trim($explicitCountryCode), '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($explicit !== '') {
            if (str_starts_with($digits, $explicit)) {
                $digits = substr($digits, strlen($explicit));
            }

            return [$explicit, $digits];
        }

        if (!str_starts_with(trim($phone), '+') || $digits === '') {
            return ['', $digits];
        }

        $oneDigit = ['1', '7'];
        $twoDigit = [
            '20', '27', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39',
            '40', '41', '43', '44', '45', '46', '47', '48', '49',
            '51', '52', '53', '54', '55', '56', '57', '58',
            '60', '61', '62', '63', '64', '65', '66',
            '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
        ];

        $first = substr($digits, 0, 1);
        if (in_array($first, $oneDigit, true)) {
            return [$first, substr($digits, 1)];
        }

        $two = substr($digits, 0, 2);
        if (in_array($two, $twoDigit, true)) {
            return [$two, substr($digits, 2)];
        }

        return [substr($digits, 0, 3), substr($digits, 3)];
    }

    private function isLocked(mixed $lockStatus): bool
    {
        if (is_bool($lockStatus)) {
            return $lockStatus;
        }

        $value = strtolower(trim((string) $lockStatus));

        return in_array($value, ['1', 'true', 'locked', 'lock', 'clienttransferprohibited'], true);
    }

    private function isNegativeRenewability(mixed $status): bool
    {
        if ($status === null) {
            return false;
        }

        if (is_bool($status)) {
            return $status === false;
        }

        $value = strtolower(trim((string) $status));

        return in_array($value, ['0', 'false', 'no', 'notrenewable', 'not_renewable'], true);
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
}
