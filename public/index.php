<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * 单入口：加载配置 -> 组装适配器 -> 鉴权 -> 分发到控制器。
 */

declare(strict_types=1);

use Edas\Lite\Adapters\DomainNameApiAdapter;
use Edas\Lite\Adapters\NameComAdapter;
use Edas\Lite\Adapters\NameSiloAdapter;
use Edas\Lite\Http\Controller\DomainController;
use Edas\Lite\Http\Controller\OrderController;
use Edas\Lite\Http\Response;
use Edas\Lite\Order\OrderService;
use Edas\Lite\Router\RegistrarRouter;
use Edas\Lite\Storage\Database;
use Edas\Lite\Storage\IdempotencyStore;
use GuzzleHttp\Client;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// 基础路径用于解析 DB_PATH 等相对路径
$_ENV['EDAS_BASE_PATH'] = $root;

\Dotenv\Dotenv::createImmutable($root)->safeLoad();

$env = static function (string $name, ?string $default = null): ?string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if (!is_string($value) || $value === '') {
        return $default;
    }

    return $value;
};

$debug = in_array(strtolower((string) $env('APP_DEBUG', 'false')), ['1', 'true', 'yes', 'on'], true);

ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[edas-lite][fatal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    Response::error(
        'internal_error',
        $debug ? $e->getMessage() : '服务器内部错误',
        500,
        $debug ? ['type' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()] : []
    );
});

// ---------------------------------------------------------------------------
// 组装
// ---------------------------------------------------------------------------

$timeout = (int) $env('HTTP_TIMEOUT', '30');

$database = new Database($env('DB_PATH'));
$idempotency = new IdempotencyStore($database);

$http = new Client([
    'timeout'         => $timeout,
    'connect_timeout' => min(10, $timeout),
    'http_errors'     => false,
    'allow_redirects' => false,
    'headers'         => ['User-Agent' => 'EDAS-Lite/1.0 (+https://zxynb.com.cn)'],
]);

$adapters = [];

if ((string) $env('NAMESILO_API_KEY', '') !== '') {
    $adapters['namesilo'] = new NameSiloAdapter([
        'apiKey'  => (string) $env('NAMESILO_API_KEY'),
        'timeout' => $timeout,
    ], $http);
}

if ((string) $env('NAMECOM_USERNAME', '') !== '' && (string) $env('NAMECOM_TOKEN', '') !== '') {
    $adapters['namecom'] = new NameComAdapter([
        'username' => (string) $env('NAMECOM_USERNAME'),
        'token'    => (string) $env('NAMECOM_TOKEN'),
        'apiBase'  => (string) $env('NAMECOM_API_BASE', 'https://api.name.com'),
        'timeout'  => $timeout,
    ], $http);
}

if ((string) $env('DOMAINNAMEAPI_RESELLER_ID', '') !== '' && (string) $env('DOMAINNAMEAPI_API_KEY', '') !== '') {
    $adapters['domainnameapi'] = new DomainNameApiAdapter([
        'resellerId' => (string) $env('DOMAINNAMEAPI_RESELLER_ID'),
        'apiKey'     => (string) $env('DOMAINNAMEAPI_API_KEY'),
        'apiBase'    => (string) $env('DOMAINNAMEAPI_API_BASE', 'https://api.domainresellerapi.com'),
        'timeout'    => $timeout,
    ], $http);
}

if ($adapters === []) {
    Response::error('configuration_error', '没有任何注册商凭证，请在 .env 中至少配置一家', 500);
    exit;
}

$priority = array_values(array_filter(array_map(
    static fn ($item) => strtolower(trim($item)),
    explode(',', (string) $env('REGISTRAR_PRIORITY', 'namesilo,namecom'))
)));

$smartPricing = in_array(
    strtolower((string) $env('SMART_PRICE_ROUTING', 'false')),
    ['1', 'true', 'yes', 'on'],
    true
);

$router = new RegistrarRouter($adapters, $priority, $smartPricing);
$orders = new OrderService($database, $idempotency, $router, (string) $env('APP_KEY', ''));

$domainController = new DomainController($router, $orders);
$orderController = new OrderController($orders);

// ---------------------------------------------------------------------------
// 请求解析
// ---------------------------------------------------------------------------

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
    $uri = substr($uri, strlen($scriptDir)) ?: '/';
}

$uri = '/' . ltrim($uri, '/');
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

$rawBody = file_get_contents('php://input') ?: '';
$body = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$authorization = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;

if ($authorization === null && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorization = $value;
            break;
        }
    }
}

$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;

// ---------------------------------------------------------------------------
// 路由表
// ---------------------------------------------------------------------------

$routes = [
    ['GET', '#^/api/v1/health$#', static function () use ($database, $router): void {
        Response::success([
            'status'    => 'ok',
            'service'   => 'EDAS Lite',
            'version'   => '1.0.0',
            'time'      => gmdate('Y-m-d\TH:i:s\Z'),
            'database'  => [
                'path'   => basename($database->path()),
                'orders' => $database->hasTable('orders'),
            ],
            'registrars' => $router->available(),
        ]);
    }],

    ['POST', '#^/api/v1/domains/check$#', static function () use ($domainController, $body): void {
        $domainController->check($body);
    }],

    ['POST', '#^/api/v1/orders$#', static function () use ($orderController, $body, $idempotencyKey): void {
        $orderController->create($body, $idempotencyKey);
    }],

    ['POST', '#^/api/v1/orders/([A-Za-z0-9_\-]{1,64})/confirm-payment$#', static function (array $m) use ($orderController, $idempotencyKey, $authorization): void {
        $orderController->confirmPayment($m[1], $idempotencyKey, $authorization);
    }],

    ['POST', '#^/api/v1/domains/transfer$#', static function () use ($domainController, $body): void {
        $domainController->transfer($body);
    }],

    ['GET', '#^/api/v1/domains/([^/]+)$#', static function (array $m) use ($domainController): void {
        $domainController->getInfo(rawurldecode($m[1]));
    }],

    ['GET', '#^/api/v1/domains/([^/]+)/dns$#', static function (array $m) use ($domainController): void {
        $domainController->getDns(rawurldecode($m[1]));
    }],

    ['PUT', '#^/api/v1/domains/([^/]+)/dns$#', static function (array $m) use ($domainController, $body): void {
        $domainController->setDns(rawurldecode($m[1]), $body);
    }],

    ['POST', '#^/api/v1/domains/([^/]+)/renew$#', static function (array $m) use ($domainController, $body): void {
        $domainController->renew(rawurldecode($m[1]), $body);
    }],

    ['GET', '#^/api/v1/domains/([^/]+)/epp$#', static function (array $m) use ($domainController): void {
        $domainController->getEpp(rawurldecode($m[1]));
    }],

    ['DELETE', '#^/api/v1/domains/([^/]+)/dns/([^/]+)$#', static function (array $m) use ($domainController): void {
        $domainController->deleteDns(rawurldecode($m[1]), rawurldecode($m[2]));
    }],

    ['GET', '#^/api/v1/domains/transfer/([^/]+)$#', static function (array $m) use ($domainController): void {
        $domainController->transferStatus(rawurldecode($m[1]));
    }],

    ['GET', '#^/api/v1/orders$#', static function () use ($orderController): void {
        $orderController->index($_GET);
    }],

    ['GET', '#^/api/v1/orders/([A-Za-z0-9_\-]{1,64})$#', static function (array $m) use ($orderController): void {
        $orderController->show($m[1]);
    }],

    ['DELETE', '#^/api/v1/orders/([A-Za-z0-9_\-]{1,64})$#', static function (array $m) use ($orderController): void {
        $orderController->cancel($m[1], isset($_GET['reason']) ? (string) $_GET['reason'] : null);
    }],
];

// ---------------------------------------------------------------------------
// 鉴权中间件（/api/v1/health 除外）
// ---------------------------------------------------------------------------

$isHealth = (bool) preg_match('#^/api/v1/health$#', $uri);

if (!$isHealth) {
    $appKey = (string) $env('APP_KEY', '');

    if ($appKey === '') {
        Response::error('configuration_error', '服务未配置 APP_KEY，拒绝所有请求', 500);
        exit;
    }

    if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
        Response::error('unauthorized', '缺少 Authorization: Bearer <APP_KEY>', 401);
        exit;
    }

    if (!hash_equals($appKey, trim($m[1]))) {
        Response::error('unauthorized', 'APP_KEY 不匹配', 401);
        exit;
    }
}

// ---------------------------------------------------------------------------
// 分发
// ---------------------------------------------------------------------------

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method) {
        continue;
    }

    if (preg_match($pattern, $uri, $matches) === 1) {
        $handler(array_slice($matches, 1));

        return;
    }
}

Response::error('not_found', sprintf('未知端点: %s %s', $method, $uri), 404);
