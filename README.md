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
- [Giorgio IM React](https://github.com/m-finder/giorgio-im-react)：React + TypeScript 即时通讯组件
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket)：Laravel 即时通讯后端
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket)：Laravel WebSocket 底层服务相关包

## 快速体验包

从 [Releases 页面](https://github.com/m-finder/giorgio-im-socket/releases) 下载适合系统的 `im-test-*` 独立程序（Windows 为 `.exe`），运行后按终端提示打开演示页面。无需安装 PHP、Node.js、Composer 或 Redis；运行 `--help` 可查看端口等参数。另有需要兼容 PHP 的 `.phar` 包。

## 一键安装本地演示

演示安装脚本最低要求 PHP 8.0.2+（`pdo_sqlite`、`mbstring`），新建项目使用 Laravel 9；已有 Laravel 9/10 项目需满足自身依赖要求；另需 Composer、Node.js/npm 和 Redis。脚本自动安装前后端、配置 SQLite 并构建演示页面，使用 PHP 原生 Socket 驱动。

```bash
curl -fsSL https://raw.githubusercontent.com/m-finder/giorgio-im-socket/main/tools/setup.sh -o /tmp/giorgio-im-setup.sh
bash /tmp/giorgio-im-setup.sh
```

按提示确认安装到当前 Laravel 项目，或选择空目录创建新项目。每次运行都需输入具有 `read:packages` 权限且能访问前端包的 [GitHub classic PAT](https://github.com/settings/tokens)，输入不会显示。

安装完成后，在项目根目录打开四个终端，各运行一条命令：

```bash
redis-server --bind 127.0.0.1 --port 16380 --save '' --appendonly no
php artisan socket:start
php artisan socket:events
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

打开 `http://127.0.0.1:8001/im`，注册两个账号即可测试私聊和群聊。自定义端口时，以脚本打印的启动命令和地址为准。

<details>
<summary>已有项目、端口与文件改动</summary>

- 指定已有项目：`bash /tmp/giorgio-im-setup.sh /项目目录`。查看用法：`bash /tmp/giorgio-im-setup.sh --help`。
- HTTP、Socket、Redis 默认端口依次为 `8001`、`9501`、`16380`；运行前可设置 `GIORGIO_IM_HTTP_PORT`、`GIORGIO_IM_SOCKET_PORT`、`GIORGIO_IM_REDIS_PORT`。
- 脚本会写入演示页面、User 模型、路由与配置，并修改 `.env` 使用 `storage/app/im-demo/database.sqlite`。改动前的已有文件备份到 `storage/app/im-demo-backups/`；再次运行保留数据库数据和有效密钥。
- token 仅用于本次依赖安装，不写入项目文件。`.npmrc` 使用 `${GIORGIO_IM_NPM_TOKEN}` 占位符，以后手动安装前端依赖需先设置该环境变量。

</details>

## 手动安装

用于接入已有 Laravel 项目。最低要求 PHP 8.0.2+、Laravel 9+，并满足项目依赖；需启用 `mbstring`，准备数据库和 Redis。

### 1. 安装并发布配置

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

### 2. 配置用户与认证

在 `config/im.php` 中指定用户模型和项目已有的认证中间件：

```php
'user_model' => App\Models\User::class,
'routes' => [
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
],
```

`auth:sanctum` 仅为示例，使用前需自行接入 Sanctum；也可替换为项目的认证方式。认证后 `$request->user()` 须返回当前用户，用户主键须为正整数，用户表与 IM 表须使用同一数据库连接。

### 3. 配置并启动服务

在 `.env` 中配置 Socket，Redis 使用项目现有配置：

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_DRIVER=auto
SOCKET_PREFIX=giorgio-im:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-private-random-secret
IM_SOCKET_URL=ws://127.0.0.1:9501
```

确认用户表已创建，执行迁移：

```bash
php artisan migrate
```

在两个终端分别启动 Socket 和事件推送：

```bash
php artisan socket:start
php artisan socket:events
```

以上地址用于本地开发；公网使用 HTTPS/WSS，推送密钥仅保存在服务端。修改配置或代码后重启这两个服务。

<details>
<summary>驱动与可选配置</summary>

- `auto` 在非 Windows 环境优先使用 Swoole，否则使用 PHP 原生驱动；Windows 原生驱动最多支持 60 个并发连接。
- IM 默认配置 Socket 鉴权、在线回调和 `im_events` 事件表；需要自行配置时关闭 `im.configure_socket`。
- 上传、签名通知和敏感词开关见下方“其他配置”。

</details>

### 4. 接入前端

按 [Vue](https://github.com/m-finder/giorgio-im#安装) 或 [React](https://github.com/m-finder/giorgio-im-react#安装) 文档安装组件，使用登录后取得的访问令牌连接：

```ts
import { createConnectedIM } from '@m-finder/giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'zh-CN' },
})
im.open()
// 页面卸载时调用 im.destroy()
```

React 项目将导入路径替换为 `@m-finder/giorgio-im-react`。

## 连接认证

认证后的 `POST /api/im/socket-ticket` 为当前用户签发短期票据,默认票据有效期 60 秒，连接最长一小时，签发端和 Socket 服务须使用同一个 `APP_KEY`

默认身份适配器只确认用户存在，需要检查登录会话或令牌是否吊销时，实现 `GiorgioImSocket\Contracts\SocketIdentity` 并配置 `im.socket_identity`：

```php
public function claims(Request $request): array;
public function resolve(array $claims): ?int;
```

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
| `PATCH` | `/groups/{id}/notifications` | 设置当前用户的群消息静音，提交 `{ muted: boolean }` |
| `PATCH` | `/groups/{id}/members/{user}/admin` | 设置管理员 |
| `GET` / `POST` | `/messages` | 历史消息和发送消息 |
| `POST` | `/messages/read` | 已读回执 |
| `POST` | `/conversations/typing` | 输入状态 |
| `POST` | `/uploads` | 上传图片、文件或语音 |
| `GET` | `/files/{id}` | 读取签名附件 |

私聊和群聊的 `type` 分别为 `friend`、`group`。发送消息需提供 UUID `mid`，用于幂等重试，附件须先上传
多图消息可使用 `attachments` 图片数组与 `content` 文字一起发送；单附件继续使用 `attachment`

群聊支持 `mentions: { id, username, start, end }[]`，索引按正文的 UTF-16 编码计算，结束位置不包含在内。服务端校验成员身份、文字范围及重叠。

群消息静音是个人设置，与群禁言独立。初始化及设置接口返回的群信息包含 `notificationsMuted`，设置后仅向当前用户同步 `group.updated`；消息仍完整投递，由前端保留未读计数并对 @ 自己的消息继续提醒。升级后运行 `php artisan migrate`，添加个人群通知设置字段。

私聊和群聊引用消息时，提交 `replyTo: { mid, fromid }`。服务端只允许引用当前会话内可见的消息，并返回含 `username`、`content` 的快照；图片消息同时保留 `attachment` 或 `attachments`，供前端展示缩略图及文字。`mentions` 和 `replyTo` 均随实时事件与历史消息返回，同一 `mid` 重试时必须保持一致。升级后运行 `php artisan migrate`，添加消息上下文字段。

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

MIT，见 [LICENSE](LICENSE)
