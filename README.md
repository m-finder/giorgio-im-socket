# Giorgio IM Socket

<p align="center"><img src="https://m-finder.github.io/images/avatar.jpeg"></p><p align="center">
<img src="https://img.shields.io/badge/Author-m--finder-red" alt="Author">
<a href="https://packagist.org/packages/wu/giorgio-im-socket"><img src="https://img.shields.io/badge/License-MIT-green" alt="License"></a>
<a href="https://packagist.org/packages/wu/giorgio-im-socket"><img src="https://img.shields.io/packagist/v/wu/giorgio-im-socket" alt="Latest Stable Version"></a>
<img src="https://img.shields.io/badge/PHP-8.0.2%2B-777BB4" alt="PHP 8.0.2+">
<img src="https://img.shields.io/badge/Laravel-9%2B-FF2D20" alt="Laravel 9+">
<img src="https://img.shields.io/badge/Swoole-Optional-25A162" alt="Swoole is optional">
</p>

[简体中文](README.md) | [English](README.en.md)

Giorgio IM 的 Laravel 后端，提供好友、群聊、消息和实时事件接口

<p align="center"><img src="https://repository-images.githubusercontent.com/1440670842/afcc1279-de3b-4152-99af-2892e552f1f4"></p>


## 相关包

- [Giorgio IM](https://github.com/m-finder/giorgio-im)：Vue 3 + TypeScript 即时通讯组件
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket)：Laravel 即时通讯后端
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket)：Laravel WebSocket 底层服务相关包

## 安装

需要 PHP 8.0.2+、Laravel 9+、mbstring、数据库和 Redis，Swoole 可选

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
```

升级本包后执行迁移：

```bash
composer update wu/giorgio-im-socket
php artisan migrate
```

## 配置

在 `config/im.php` 指定现有用户模型和认证中间件：

```php
'user_model' => App\Models\User::class,
'routes' => [
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
],
```

本包不依赖 Sanctum，认证方式由应用决定，只需保证 `$request->user()` 返回当前用户。用户主键须为正整数，用户表与 IM 表使用同一数据库连接

先创建用户表，再迁移并发布 Socket 配置：

```bash
php artisan migrate
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

配置 `.env`：

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_DRIVER=auto
SOCKET_PREFIX=giorgio-im:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-private-random-secret
IM_SOCKET_URL=ws://127.0.0.1:9501
IM_UPLOAD_DISK=local
IM_SIGNATURE_NOTICES_ENABLED=true
IM_SENSITIVE_WORDS_ENABLED=false
```

启动服务：

```bash
php artisan socket:start
php artisan socket:events
```

`auto` 在非 Windows 环境优先使用 Swoole，否则使用 PHP 原生服务端。Windows 原生驱动最多支持 60 个并发连接。IM 默认配置 Socket 鉴权、在线回调和 `im_events` 事件表；关闭 `configure_socket` 可自行配置

公网使用 HTTPS/WSS，推送密钥只保存在服务端。配置或代码变更后重启服务

## 连接认证

认证后的 `POST /api/im/socket-ticket` 为当前用户签发短期票据。默认票据有效期 60 秒，连接最长一小时，签发端和 Socket 服务须使用同一个 `APP_KEY`

默认身份适配器只确认用户存在。需要检查登录会话或令牌是否吊销时，实现 `GiorgioImSocket\Contracts\SocketIdentity` 并配置 `im.socket_identity`：

```php
public function claims(Request $request): array;
public function resolve(array $claims): ?int;
```

## 前端接入

```ts
import { createConnectedIM } from 'giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'zh-CN' },
})
im.open()
// 页面卸载时调用 im.destroy()
```

`apiUrl` 应与 `routes.prefix` 一致。组件从初始化接口获取 Socket 地址并建立连接，AI 和 RTC 信令由应用接入

## HTTP 接口

除签名文件读取外，接口均需认证。JSON 接口使用 `Accept: application/json`，上传使用 multipart

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET` | `/init` | 用户、好友、群、通知、黑名单、事件游标和 Socket 地址 |
| `POST` | `/socket-ticket` | 签发连接票据 |
| `GET` | `/events?after=ID` | 补取事件 |
| `PATCH` | `/user` | 修改资料 |
| `GET` | `/users?q=text` | 搜索用户 |
| `POST` | `/friend-groups` | 创建好友分组 |
| `POST` | `/friend-requests` | 申请好友 |
| `PATCH` / `DELETE` | `/friends/{id}/group`、`/friends/{id}` | 移动或删除好友 |
| `GET` / `POST` / `DELETE` | `/blocks`、`/blocks/{id}` | 黑名单 |
| `POST` | `/notices/{id}` | 处理好友申请或通知 |
| `POST` / `PATCH` / `DELETE` | `/groups`、`/groups/{id}` | 创建、修改或解散群 |
| `GET` / `POST` / `DELETE` | `/groups/{id}/members` | 群成员管理 |
| `PATCH` | `/groups/{id}/mute`、`/groups/{id}/members/{user}/mute` | 禁言 |
| `PATCH` | `/groups/{id}/members/{user}/admin` | 设置管理员 |
| `GET` / `POST` | `/messages` | 历史消息和发送消息 |
| `POST` | `/messages/read` | 已读回执 |
| `POST` | `/conversations/typing` | 输入状态 |
| `POST` | `/uploads` | 上传图片、文件或语音 |
| `GET` | `/files/{id}` | 读取签名附件 |

私聊和群聊的 `type` 分别为 `friend`、`group`。发送消息需提供 UUID `mid`，用于幂等重试。附件须先上传

## 其他配置

| 配置 | 默认值 |
| --- | --- |
| `user_model` | `auth.providers.users.model` |
| `socket_identity` | `UserIdentity` |
| `socket_ticket_ttl` / `socket_session_ttl` | 60 / 3600 秒 |
| `history_limit` | 50 条 |
| `upload_max_kb` / `disk` | 10240 / `local` |
| `IM_SIGNATURE_NOTICES_ENABLED` | `true` |
| `IM_SENSITIVE_WORDS_ENABLED` | `false` |

敏感词词库通过 `IM_SENSITIVE_WORDS_PATH` 指定

## 拉黑

- 拉黑会删除双方好友关系，拒绝未处理的好友申请和后续私聊，保留历史消息
- 解除拉黑不会恢复好友关系

运行测试：`composer test`

MIT，见 [LICENSE](LICENSE)
