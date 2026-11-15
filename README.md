# EDAS Lite（EasyDomainAPI System Lite）

`AGPL-3.0-or-later` · `PHP 8.1+` · `状态：开发中`

面向自部署的域名注册商统一 API 层，用一套接口统一 NameSilo 与 Name.com 的域名查询、注册、续费、转入、DNS 管理与 EPP 码获取。

---

## 1. 关于华阳互联

EDAS Lite 由**华阳互联**开发与维护。

- 官网：https://zxynb.com.cn
- GitHub：https://github.com/hyhl-tech

---

## 2. 特性

- **统一适配层**：一套 `RegistrarAdapterInterface`，三家注册商共用同一种调用方式，业务代码不需要关心底层是 GET 拼 query 还是 REST JSON。
- **NameSilo 完整适配**：覆盖可用性查询、注册、续费、转入、DNS、EPP 码、锁定、隐私；正确处理 `301`/`302` 这类「已成功但存在附加情况」的响应码，不会被误判为失败。
- **Name.com 完整适配**：基于 Core API v1，注册与续费请求强制携带 `X-Idempotency-Key`，网络重试不会造成重复扣费。
- **订单状态机 + 幂等键存储**：订单状态流转落库，`confirm-payment` 重复调用返回首次结果，不重复触发注册。
- **domain → registrar 本地映射**：已注册域名在本地建立归属映射，后续查询自动路由到对应注册商，不做跨商重复探测。
- **SQLite 存储**：单文件数据库，无需额外部署数据库服务，备份即拷贝一个文件。
- **支付外置**：Lite 不接触任何支付密钥、卡信息或收款流程。

---

## 3. 范围与边界

**Lite 是 API 层，不是完整财务系统。**它的职责边界是「把三家注册商的差异抹平，并提供可追踪的订单状态」，到此为止。

### 包含

- NameSilo / Name.com 的可用性查询、注册、续费、转入、DNS 记录管理、EPP 码获取
- 统一 `RegistrarAdapterInterface` 与适配器注册表
- 订单创建、状态机流转、幂等键存储
- domain → registrar 本地映射与自动路由
- SQLite 持久化与迁移脚本
- Bearer Token 形式的调用方鉴权

### 不包含（留给 Pro / 由调用方自行实现）

- WHMCS、魔方财务等财务面板对接
- TLD 价格自动同步与智能路由
- 自动选择最低价注册商
- 批量操作（批量查询、批量注册、批量续费）
- Web 管理面板
- 支付收款（下单、收款、对账、退款全部在 Lite 之外）
- 定时同步到期日与到期提醒

---

## 4. 环境要求

- PHP **8.1+**
- 扩展：`pdo_sqlite`、`curl`、`xml`、`mbstring`
- Composer 2.x

检查扩展是否齐全：

```bash
php -m | grep -E 'pdo_sqlite|curl|xml|mbstring'
```

---

## 5. 安装

```bash
composer create-project hyhl-tech/edas-lite edas
cd edas
php -S localhost:8000 -t public
```

说明两点：

1. `post-create-project-cmd` 会自动把 `.env.example` 复制为 `.env`，并执行数据库迁移（创建 `storage/edas.sqlite` 与所需表）。装完不需要手动建库。
2. 本项目 `type: project`，作为可部署应用使用，**请把 `composer.lock` 一起提交到版本库**，以保证每台机器装出一致的依赖版本。`.gitignore` 中刻意没有忽略它。

装完先不要急着改用例，按下一节「配置」填好凭证，再跑「快速自检」。

---

## 6. 配置

所有配置集中在项目根目录 `.env`。

| 变量 | 必填 | 说明 |
| --- | --- | --- |
| `APP_ENV` | 否 | 运行环境，`production` / `development`，默认 `production` |
| `APP_DEBUG` | 否 | 是否输出详细异常栈，生产环境务必 `false` |
| `APP_URL` | 否 | 服务对外地址，用于日志与回调拼接，如 `https://edas.example.com` |
| `APP_KEY` | **是** | EDAS 自身鉴权密钥，调用方需在请求头携带 `Authorization: Bearer <APP_KEY>` |
| `DB_PATH` | 否 | SQLite 文件路径，默认 `storage/edas.sqlite` |
| `LOG_LEVEL` | 否 | 日志级别 `debug` / `info` / `warning` / `error` |
| `HTTP_TIMEOUT` | 否 | 调用注册商接口的超时秒数，默认 `30` |
| `NAMESILO_API_KEY` | NameSilo 必填 | NameSilo API 密钥 |
| `NAMECOM_USERNAME` | Name.com 必填 | Name.com 账户用户名 |
| `NAMECOM_TOKEN` | Name.com 必填 | Name.com API Token |
| `NAMECOM_API_BASE` | 否 | 默认 `https://api.name.com`，沙箱用 `https://api.dev.name.com` |
| `REGISTRAR_PRIORITY` | **是** | 注册商优先级，逗号分隔，如 `namesilo,namecom` |

### 四个高频坑

**1. `NAMESILO_API_KEY` 必须同时配置 IP 白名单**

密钥在 NameSilo 后台 **API Manager** 页面生成。生成后还必须把**服务器的公网 IP** 加入该页面的白名单，否则所有请求返回业务码 **110（Invalid API key）** —— 这个错误码看起来像「密钥写错了」，实际上九成是 IP 不在白名单。注意是服务器出网 IP，不是你本机 IP；容器或 NAT 环境下要查实际出口 IP。

**2. `NAMECOM_TOKEN` 不是登录密码**

`NAMECOM_USERNAME` 填账户用户名，`NAMECOM_TOKEN` 必须在 Name.com 后台 **API Token** 页面单独生成。**用登录密码填进去会直接 `401 Unauthorized`**，而 `401` 又常被误判成「EDAS 的 APP_KEY 错了」，排查时先确认错的是哪一层。沙箱环境另有规则：用户名后加 `-test`，配沙箱 token，并把 `NAMECOM_API_BASE` 指向 `https://api.dev.name.com`。

**3. `REGISTRAR_PRIORITY` 只列已配置凭证的注册商**

这个变量决定路由顺序。**只写你真正配好凭证的那几家**。如果写了 `namesilo,namecom` 但 Name.com 凭证是空的，一旦路由落到它，适配器会以占位状态抛出异常，订单会卡在创建阶段。只配了一家就只写一家，多写不会带来容错，只会带来噪音。

**4. `APP_KEY` 是 EDAS 自己的门锁，与注册商密钥无关**

它保护的是 **EDAS 的 HTTP 接口**，不是注册商 API。用下面的命令生成：

```bash
openssl rand -hex 32
```

调用时放在请求头：

```
Authorization: Bearer <APP_KEY>
```

`APP_KEY` 泄露等于把你的注册商额度交出去，不要提交到版本库（`.env` 已在 `.gitignore` 中）。

---

## 7. 快速自检

按顺序跑，三步依次通过再往下走。这套顺序的价值在于：**它能区分「凭证错」和「代码问题」**。

**第一步：服务是否活着（不校验任何上游凭证）**

```bash
curl -s http://localhost:8000/api/v1/health \
  -H "Authorization: Bearer $APP_KEY"
```

返回 `{"status":"ok"}` 说明 EDAS 进程、SQLite、`APP_KEY` 都正常。这里失败就是部署问题，与注册商无关。

**第二步：注册商凭证是否有效（会真实打上游接口）**

```bash
curl -s -X POST http://localhost:8000/api/v1/domains/check \
  -H "Authorization: Bearer $APP_KEY" \
  -H "Content-Type: application/json" \
  -d '{"domain":"example.com"}'
```

这一步通过，说明名称解析、TLS、密钥、IP 白名单全部可用。**如果这一步报 `110` 或 `401`，问题在注册商侧配置，不要在业务代码里找。**

**第三步：订单与注册（会产生真实费用，谨慎使用）**

```bash
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Authorization: Bearer $APP_KEY" \
  -H "Content-Type: application/json" \
  -d '{"domain":"your-test-domain.com","years":1,"registrar":"namesilo"}'
```

拿到订单号后调 `confirm-payment` 才会真正注册。建议先用便宜 TLD 或沙箱验证整条链路。

---

## 8. API 端点一览

所有端点均需携带 `Authorization: Bearer <APP_KEY>`。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/api/v1/health` | 健康检查，返回服务与数据库状态，不访问上游 |
| `POST` | `/api/v1/domains/check` | 查询域名可用性与价格，按 `REGISTRAR_PRIORITY` 路由 |
| `POST` | `/api/v1/orders` | 创建订单，写入状态机，**不扣费、不注册** |
| `POST` | `/api/v1/orders/{id}/confirm-payment` | 确认已收款，触发注册；幂等，重复调用返回首次结果 |
| `GET` | `/api/v1/domains/{domain}` | 查询已注册域名详情（状态、到期日、NS、锁定、隐私） |
| `GET/POST/PUT/DELETE` | `/api/v1/domains/{domain}/dns` | DNS 记录列表与增删改 |
| `POST` | `/api/v1/domains/{domain}/renew` | 续费指定年限 |
| `POST` | `/api/v1/domains/transfer` | 提交转入（含 EPP 码），或查询转入状态 |

---

## 9. 支付说明

**Lite 只创建订单，不处理支付。**

`POST /api/v1/orders` 仅仅是在本地写一条待支付订单，不会向上游发起任何注册请求。调用方（你自己的业务系统）自行完成收款 —— 无论走什么渠道 —— 收款确认后再调 `POST /api/v1/orders/{id}/confirm-payment`，Lite 才真正调用注册商注册接口。

两点必须清楚：

- **余额由你自己维护。** Lite 不在 NameSilo / Name.com 账户之间做资金调度。你需要在这两家的账户里自行充值，或绑定有效的信用卡 / 支付方式。账户余额不足时，Lite 不做任何兜底或垫付。
- **错误码原样透传。** 余额不足、卡片不可用、注册局拒单等情况，适配器会把注册商返回的原始错误码和信息透传给你（NameSilo 表现为业务码如 `119`，Name.com 表现为 HTTP 4xx 与 `message` 文本），由调用方决定如何提示用户、是否重试。Lite 不翻译、不吞掉这些信息。

---

## 10. 许可证

本项目采用 **AGPL-3.0-or-later**，详见根目录 `LICENSE` 文件。

AGPL 的关键点在于网络服务条款：**如果你修改了本项目并把它作为网络服务对外提供，你需要向使用该服务的用户提供你所修改后的完整源码。** 自用而不对外提供服务时，则不触发这一义务。

---

## 11. 贡献与联系

欢迎提交 issue 与 PR：https://github.com/hyhl-tech/edas-lite

提交前请确保改动通过现有测试，并尽量附带能复现问题的用例。涉及适配器行为的改动，请说明对应的注册商文档依据。

商业支持、定制开发与 Pro 版本咨询，请联系华阳互联：https://zxynb.com.cn

---

华阳互联 · https://zxynb.com.cn · https://github.com/hyhl-tech
