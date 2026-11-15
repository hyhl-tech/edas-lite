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
 * NameSilo 适配器。
 *
 * NameSilo 的接口风格与 REST 完全不同：
 *   - 全部操作走 GET，参数拼在 query string 上；
 *   - 返回 XML，业务结果在 <reply><code> 里，与 HTTP 状态码无关；
 *   - 300 才是成功，301/302 表示「已经成功但存在附加情况」。
 *
 * 因此本类把所有 XML 解析集中到 parse()，把错误码语义集中到 interpret()，
 * 避免各方法重复判断。
 */
final class NameSiloAdapter implements RegistrarAdapterInterface
{
    private const ENDPOINT = 'https://www.namesilo.com/api/';

    /** 同时作为 checkAvailability 的批量上限，NameSilo 未强制，保守取值 */
    private const BATCH_LIMIT = 50;

    private const MESSAGES = [
        '110' => 'NameSilo 拒绝了该 API Key，或调用方服务器 IP 不在 API Manager 白名单内',
        '200' => '域名不属于该账户或未激活',
        '300' => '成功',
        '301' => '域名已注册成功，但提交的 nameserver 未通过注册局校验，已改用 NameSilo 默认 nameserver',
        '302' => '订单已提交成功，但联系资料不合法，已改用账户默认联系人资料',
        '303' => '域名已被注册',
        '304' => '该域名当前不可注册（扩展名不受支持或被注册局保留）',
        '119' => '账户余额不足',
        '210' => 'NameSilo 返回通用错误',
        '261' => '域名处理错误',
        '263' => '续费年限不合法',
        '264' => '无法按指定年限续费',
        '265' => '当前状态下无法转入该域名',
        '266' => '该账户下不存在此域名的转入记录',
        '267' => '域名格式非法，或该扩展名不受支持',
    ];

    /**
     * @param array{apiKey?: string, timeout?: int} $config
     */
    public function __construct(
        private array $config,
        private ClientInterface $http
    ) {
        if (($this->config['apiKey'] ?? '') === '') {
            throw new RuntimeException('NameSilo 适配器缺少 apiKey 配置');
        }
    }

    public function getRegistrarName(): string
    {
        return 'namesilo';
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

        if ($domains === []) {
            return [];
        }

        $result = [];
        foreach ($domains as $domain) {
            $result[$domain] = $this->availabilityStub($domain);
        }

        foreach (array_chunk($domains, self::BATCH_LIMIT) as $chunk) {
            $reply = $this->call('checkRegisterAvailability', [
                'domains' => implode(',', $chunk),
            ]);

            $code = (string) $reply['code'];
            if ($code !== '300') {
                // 批量查询失败时保留 stub，把原因写进 reason，交由上层决定是否重试
                foreach ($chunk as $domain) {
                    $result[$domain]['reason'] = $this->message($code);
                }

                continue;
            }

            $this->mergeAvailability($result, $reply['reply']);
        }

        return $result;
    }

    public function register(string $domain, int $years, array $params): array
    {
        $query = [
            'domain'     => $domain,
            'years'      => $years,
            'private'    => ($params['private'] ?? true) ? 1 : 0,
            'auto_renew' => ($params['autoRenew'] ?? true) ? 1 : 0,
        ];

        if (!empty($params['promoCode'])) {
            $query['coupon'] = (string) $params['promoCode'];
        }

        // ns1..ns13
        $nameservers = array_values(array_filter(array_map(
            static fn ($ns) => trim((string) $ns),
            (array) ($params['nameservers'] ?? [])
        )));
        foreach (array_slice($nameservers, 0, 13) as $index => $ns) {
            $query['ns' . ($index + 1)] = $ns;
        }

        // 联系人：NameSilo 要求必填字段全部提供，否则整组被忽略并回退账户默认资料
        $contacts = (array) ($params['contacts'] ?? []);
        if ($contacts !== []) {
            $map = [
                'firstName' => 'fn',
                'lastName'  => 'ln',
                'address1'  => 'ad',
                'address2'  => 'ad2',
                'city'      => 'cy',
                'state'     => 'st',
                'zip'       => 'zp',
                'country'   => 'ct',
                'email'     => 'em',
                'phone'     => 'ph',
                'company'   => 'cp',
                'fax'       => 'fx',
            ];
            foreach ($map as $from => $to) {
                if (isset($contacts[$from]) && $contacts[$from] !== '') {
                    $query[$to] = (string) $contacts[$from];
                }
            }
        }

        $reply = $this->call('registerDomain', $query);

        return $this->interpret($domain, $reply, 'register');
    }

    public function renew(string $domain, int $years): array
    {
        $reply = $this->call('renewDomain', [
            'domain' => $domain,
            'years'  => $years,
        ]);

        return $this->interpret($domain, $reply, 'renew');
    }

    public function transfer(string $domain, string $authCode, array $params): array
    {
        $query = ['domain' => $domain];

        if (isset($params['years'])) {
            $query['years'] = (int) $params['years'];
        }

        if ($authCode !== '') {
            // NameSilo 的转入接口接受 authCode；若账户侧要求先在面板提交，
            // 会返回业务错误码，由 interpret() 原样透传。
            $query['authCode'] = $authCode;
        }

        $reply = $this->call('transferDomain', $query);

        return $this->interpret($domain, $reply, 'transfer');
    }

    public function getDomainInfo(string $domain): array
    {
        $reply = $this->call('getDomainInfo', ['domain' => $domain]);
        $code = (string) $reply['code'];

        if ($code !== '300') {
            throw new RuntimeException(sprintf(
                'NameSilo getDomainInfo 失败 [%s]: %s',
                $code,
                $this->message($code)
            ));
        }

        $node = $reply['reply'];

        $nameservers = [];
        if (isset($node->nameservers)) {
            foreach ($node->nameservers->nameserver as $ns) {
                $value = trim((string) $ns);
                if ($value !== '') {
                    $nameservers[] = $value;
                }
            }
        }

        return [
            'domain'      => strtolower(trim((string) ($node->domain ?? $domain))),
            'registrar'   => $this->getRegistrarName(),
            'status'      => strtolower(trim((string) ($node->status ?? 'unknown'))),
            'expireDate'  => $this->normalizeDate((string) ($node->expires ?? '')),
            'nameservers' => $nameservers,
            'locked'      => $this->isYes((string) ($node->locked ?? '')),
            'autoRenew'   => $this->isYes((string) ($node->auto_renew ?? '')),
        ];
    }

    public function getDnsRecords(string $domain): array
    {
        $reply = $this->call('dnsListRecords', ['domain' => $domain]);
        $code = (string) $reply['code'];

        if ($code !== '300') {
            throw new RuntimeException(sprintf(
                'NameSilo dnsListRecords 失败 [%s]: %s',
                $code,
                $this->message($code)
            ));
        }

        $records = [];
        if (isset($reply['reply']->resource_record)) {
            foreach ($reply['reply']->resource_record as $node) {
                $records[] = [
                    'id'       => (string) ($node->record_id ?? ''),
                    'type'     => strtoupper(trim((string) ($node->type ?? ''))),
                    'host'     => trim((string) ($node->host ?? '')),
                    'answer'   => trim((string) ($node->value ?? '')),
                    'ttl'      => (int) ($node->ttl ?? 3600),
                    'priority' => isset($node->distance) ? (int) $node->distance : null,
                ];
            }
        }

        return [
            'domain'  => $domain,
            'records' => $records,
        ];
    }

    /**
     * NameSilo 没有「整组替换」接口，这里实现为 upsert：
     * 同 host + type 已存在则 dnsUpdateRecord，否则 dnsAddRecord。
     * 不在入参中的既有记录不会被删除——删除是破坏性操作，不在此处隐式执行。
     */
    public function setDnsRecords(string $domain, array $records): array
    {
        $existing = [];
        try {
            foreach ($this->getDnsRecords($domain)['records'] as $record) {
                $existing[$this->recordKey($record['host'], $record['type'])] = $record['id'];
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
            $ttl = (int) ($record['ttl'] ?? 3600);
            $distance = (int) ($record['priority'] ?? 0);

            if ($type === '' || $answer === '') {
                $warnings[] = '跳过一条缺少 type 或 answer 的记录';

                continue;
            }

            $key = $this->recordKey($host, $type);
            $payload = [
                'domain'     => $domain,
                'rrtype'     => $type,
                'rrhost'     => $host,
                'rrvalue'    => $answer,
                'rrttl'      => $ttl,
                'rrdistance' => $distance,
            ];

            if (isset($existing[$key])) {
                $payload['rrid'] = $existing[$key];
                $reply = $this->call('dnsUpdateRecord', $payload);
            } else {
                $reply = $this->call('dnsAddRecord', $payload);
            }

            if ((string) $reply['code'] !== '300') {
                $warnings[] = sprintf(
                    '%s %s: [%s] %s',
                    $type,
                    $host === '' ? '@' : $host,
                    (string) $reply['code'],
                    $this->message((string) $reply['code'])
                );

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
                'message'            => '缺少 recordId（NameSilo 的 rrid）',
            ];
        }

        $reply = $this->call('dnsDeleteRecord', [
            'domain' => $domain,
            'rrid'   => $recordId,
        ]);

        $code = (string) $reply['code'];

        return [
            'success'            => $code === '300',
            'domain'             => $domain,
            'deleted'            => $code === '300',
            'registrarErrorCode' => $code === '300' ? null : $code,
            'message'            => $this->message($code),
        ];
    }

    /**
     * NameSilo 没有独立的转入进度查询端点。
     * 这里用 getDomainInfo 判断域名是否已经落到本账户：已存在且为 active 视为转入完成，
     * 否则返回 pending，并明确说明这是推断结果而非注册局状态。
     */
    public function getTransferStatus(string $domain): array
    {
        try {
            $info = $this->getDomainInfo($domain);
        } catch (RuntimeException $e) {
            return [
                'domain'        => $domain,
                'registrar'     => $this->getRegistrarName(),
                'status'        => 'unknown',
                'requestedDate' => null,
                'finalDate'     => null,
                'message'       => '无法判断转入状态: ' . $e->getMessage(),
            ];
        }

        $status = in_array($info['status'], ['active', 'ok'], true) ? 'completed' : 'pending';

        return [
            'domain'        => $domain,
            'registrar'     => $this->getRegistrarName(),
            'status'        => $status,
            'requestedDate' => null,
            'finalDate'     => $status === 'completed' ? $info['expireDate'] : null,
            'message'       => $status === 'completed'
                ? '域名已存在于本账户（依据 getDomainInfo 推断）'
                : '域名尚未出现在本账户（依据 getDomainInfo 推断）',
        ];
    }

    public function getEppCode(string $domain): array
    {
        $reply = $this->call('retrieveAuthCode', ['domain' => $domain]);
        $code = (string) $reply['code'];

        if ($code !== '300') {
            return [
                'success'            => false,
                'domain'             => $domain,
                'authCode'           => null,
                'registrarErrorCode' => $code,
                'message'            => $this->message($code),
            ];
        }

        $node = $reply['reply'];
        $authCode = trim((string) ($node->auth_code ?? ''));

        if ($authCode === '') {
            $authCode = trim((string) $reply['detail']);
        }

        return [
            'success'            => $authCode !== '',
            'domain'             => $domain,
            'authCode'           => $authCode === '' ? null : $authCode,
            'registrarErrorCode' => $authCode === '' ? '210' : null,
            'message'            => $authCode === '' ? 'NameSilo 未返回授权码' : 'success',
        ];
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * 拼接并执行一次 NameSilo 调用，返回解析后的结构。
     *
     * @return array{code: int, detail: string, reply: \SimpleXMLElement}
     */
    private function call(string $operation, array $params = []): array
    {
        $query = array_merge([
            'version' => '1',
            'type'    => 'xml',
            'key'     => (string) $this->config['apiKey'],
        ], $params);

        $url = self::ENDPOINT . $operation . '?' . http_build_query($query);

        try {
            $response = $this->http->request('GET', $url, [
                'timeout'         => (int) ($this->config['timeout'] ?? 30),
                'http_errors'     => false,
                'allow_redirects' => false,
                'headers'         => ['Accept' => 'application/xml, text/xml, */*'],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('NameSilo %s 请求失败: %s', $operation, $e->getMessage()),
                0,
                $e
            );
        }

        $body = (string) $response->getBody();

        return $this->parse($body, $operation);
    }

    /**
     * 解析 NameSilo 返回的 XML。解析失败一律抛异常，不做静默降级。
     */
    private function parse(string $xml, string $operation): array
    {
        if (trim($xml) === '') {
            throw new RuntimeException(sprintf('NameSilo %s 返回空响应体', $operation));
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            $snippet = substr(preg_replace('/\s+/', ' ', $xml) ?? '', 0, 200);

            throw new RuntimeException(sprintf(
                'NameSilo %s 返回的 XML 无法解析（前 200 字节）: %s',
                $operation,
                $snippet
            ));
        }

        if (!isset($document->reply)) {
            throw new RuntimeException(sprintf('NameSilo %s 响应缺少 <reply> 节点', $operation));
        }

        $reply = $document->reply;

        return [
            'code'   => (int) $reply->code,
            'detail' => trim((string) $reply->detail),
            'reply'  => $reply,
        ];
    }

    /**
     * 把 NameSilo 的响应码翻译成统一结构。
     * 301/302 属于「已成功但带附加情况」，必须保留为 partialSuccess。
     */
    private function interpret(string $domain, array $reply, string $action): array
    {
        $code = (string) $reply['code'];
        $detail = (string) $reply['detail'];
        $message = $this->message($code);

        if ($code === '300') {
            return $this->ok($domain, $action, $message, $detail);
        }

        if ($code === '301' || $code === '302') {
            return [
                'success'            => true,
                'domain'             => $domain,
                'orderId'            => null,
                'amount'             => null,
                'currency'           => 'USD',
                'partialSuccess'     => true,
                'warnings'           => array_values(array_filter([$message, $detail])),
                'registrarErrorCode' => $code,
                'message'            => $message,
            ];
        }

        return $this->fail($domain, $action, $code, $detail !== '' ? $detail : $message);
    }

    private function ok(string $domain, string $action, string $message, string $detail): array
    {
        return [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => null,
            'amount'             => null,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => $detail !== '' && strtolower($detail) !== 'success' ? [$detail] : [],
            'registrarErrorCode' => null,
            'message'            => $detail !== '' ? $detail : $message,
        ];
    }

    private function fail(string $domain, string $action, string $code, string $detail): array
    {
        return [
            'success'            => false,
            'domain'             => $domain,
            'orderId'            => null,
            'amount'             => null,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => $code,
            'message'            => $detail !== '' ? $detail : $this->message($code),
        ];
    }

    private function message(string $code): string
    {
        return self::MESSAGES[$code] ?? sprintf('NameSilo 返回未收录的错误码 %s', $code);
    }

    private function availabilityStub(string $domain): array
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

    private function mergeAvailability(array &$result, \SimpleXMLElement $reply): void
    {
        if (isset($reply->available)) {
            foreach ($reply->available->domain as $node) {
                $name = strtolower(trim((string) $node['name']));
                if ($name === '' || !isset($result[$name])) {
                    continue;
                }

                $price = isset($node['price']) ? (float) $node['price'] : null;
                $premiumRaw = strtolower(trim((string) ($node['premium'] ?? '0')));

                $result[$name]['available'] = true;
                $result[$name]['registerPrice'] = $price;
                $result[$name]['renewPrice'] = $price;
                $result[$name]['premium'] = in_array($premiumRaw, ['1', 'yes', 'true'], true);
                $result[$name]['reason'] = null;
            }
        }

        if (isset($reply->unavailable)) {
            foreach ($reply->unavailable->domain as $node) {
                $name = strtolower(trim((string) $node['name']));
                if ($name === '' || !isset($result[$name])) {
                    continue;
                }

                $result[$name]['available'] = false;
                $result[$name]['registerPrice'] = null;
                $result[$name]['renewPrice'] = null;
                $result[$name]['reason'] = 'unavailable';
            }
        }
    }

    private function recordKey(string $host, string $type): string
    {
        return strtolower(trim($host)) . '|' . strtoupper(trim($type));
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function isYes(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['yes', '1', 'true', 'on'], true);
    }
}
