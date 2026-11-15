<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Tests\Router;

use Edas\Lite\Contracts\RegistrarAdapterInterface;
use Edas\Lite\Router\RegistrarRouter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 路由器的两条硬规则必须被测试锁住：
 *   1. 抛异常才换下一家，业务失败（success=false）绝不重试；
 *   2. 显式指定的注册商不存在时直接报错，不能悄悄换人。
 */
final class RegistrarRouterTest extends TestCase
{
    public function testAvailableFollowsPriority(): void
    {
        $router = new RegistrarRouter(
            ['a' => new FakeAdapter('a'), 'b' => new FakeAdapter('b')],
            ['b', 'a']
        );

        self::assertSame(['b', 'a'], $router->available());
    }

    public function testAdapterMissingFromPriorityIsAppended(): void
    {
        $router = new RegistrarRouter(
            ['a' => new FakeAdapter('a'), 'b' => new FakeAdapter('b')],
            ['a']
        );

        self::assertSame(['a', 'b'], $router->available());
    }

    public function testExplicitRegistrarMustExist(): void
    {
        $router = new RegistrarRouter(['a' => new FakeAdapter('a')], ['a']);

        $this->expectException(RuntimeException::class);

        $router->getDomainInfo('example.com', 'nope');
    }

    public function testFallsBackWhenAdapterThrows(): void
    {
        $first = new FakeAdapter('a', 'getDomainInfo');
        $second = new FakeAdapter('b');

        $router = new RegistrarRouter(['a' => $first, 'b' => $second], ['a', 'b']);

        $info = $router->getDomainInfo('example.com');

        self::assertSame('b', $info['registrar']);
        self::assertContains('getDomainInfo', $first->calls);
        self::assertContains('getDomainInfo', $second->calls);
    }

    public function testBusinessFailureIsNotRetried(): void
    {
        $first = new FakeAdapter('a', null, [
            'success'            => false,
            'registrarErrorCode' => '303',
            'message'            => '域名已被注册',
        ]);
        $second = new FakeAdapter('b');

        $router = new RegistrarRouter(['a' => $first, 'b' => $second], ['a', 'b']);

        $result = $router->register('example.com', 1, []);

        self::assertFalse($result['success']);
        self::assertSame('303', $result['registrarErrorCode']);
        self::assertSame([], $second->calls, '业务失败后不应再调用第二家注册商');
    }

    public function testAllAdaptersFailingThrowsWithReasons(): void
    {
        $router = new RegistrarRouter([
            'a' => new FakeAdapter('a', 'register'),
            'b' => new FakeAdapter('b', 'register'),
        ], ['a', 'b']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/均失败/');

        $router->register('example.com', 1, []);
    }
}

/**
 * 只用于测试的假适配器：可以指定某个方法抛异常，其余返回固定结果。
 */
final class FakeAdapter implements RegistrarAdapterInterface
{
    /** @var string[] */
    public array $calls = [];

    public function __construct(
        private string $name = 'fake',
        private ?string $throwOn = null,
        private array $result = []
    ) {
    }

    public function getRegistrarName(): string
    {
        return $this->name;
    }

    public function checkAvailability(array $domains): array
    {
        $this->calls[] = 'checkAvailability';

        if ($this->throwOn === 'checkAvailability') {
            throw new RuntimeException('boom');
        }

        $out = [];
        foreach ($domains as $domain) {
            $out[strtolower($domain)] = [
                'domain'        => strtolower($domain),
                'available'     => true,
                'registerPrice' => 10.0,
                'renewPrice'    => 12.0,
                'currency'      => 'USD',
                'premium'       => false,
                'reason'        => null,
            ];
        }

        return $out;
    }

    public function register(string $domain, int $years, array $params): array
    {
        return $this->respond('register', [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => 'fake-1',
            'amount'             => 10.0,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    public function renew(string $domain, int $years): array
    {
        return $this->respond('renew', [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => 'fake-2',
            'amount'             => 12.0,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    public function transfer(string $domain, string $authCode, array $params): array
    {
        return $this->respond('transfer', [
            'success'            => true,
            'domain'             => $domain,
            'orderId'            => 'fake-3',
            'amount'             => 12.0,
            'currency'           => 'USD',
            'partialSuccess'     => false,
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    public function getDomainInfo(string $domain): array
    {
        return $this->respond('getDomainInfo', [
            'domain'      => $domain,
            'registrar'   => $this->name,
            'status'      => 'active',
            'expireDate'  => '2030-01-01T00:00:00Z',
            'nameservers' => ['ns1.example.com'],
            'locked'      => true,
            'autoRenew'   => true,
        ]);
    }

    public function getDnsRecords(string $domain): array
    {
        return $this->respond('getDnsRecords', [
            'domain'  => $domain,
            'records' => [],
        ]);
    }

    public function setDnsRecords(string $domain, array $records): array
    {
        return $this->respond('setDnsRecords', [
            'success'            => true,
            'domain'             => $domain,
            'applied'            => count($records),
            'warnings'           => [],
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        return $this->respond('deleteDnsRecord', [
            'success'            => true,
            'domain'             => $domain,
            'deleted'            => true,
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    public function getTransferStatus(string $domain): array
    {
        return $this->respond('getTransferStatus', [
            'domain'        => $domain,
            'registrar'     => $this->name,
            'status'        => 'completed',
            'requestedDate' => null,
            'finalDate'     => null,
            'message'       => 'success',
        ]);
    }

    public function getEppCode(string $domain): array
    {
        return $this->respond('getEppCode', [
            'success'            => true,
            'domain'             => $domain,
            'authCode'           => 'abc123',
            'registrarErrorCode' => null,
            'message'            => 'success',
        ]);
    }

    private function respond(string $method, array $default): array
    {
        $this->calls[] = $method;

        if ($this->throwOn === $method) {
            throw new RuntimeException(sprintf('%s boom', $method));
        }

        return $this->result === [] ? $default : $this->result;
    }
}
