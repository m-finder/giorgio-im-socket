# Giorgio IM Socket

[简体中文](README.md) | [English](README.en.md)

[Giorgio IM](https://github.com/m-finder/giorgio-im) 的 Laravel 后端接入扩展，基于 `wu/giorgio-socket` 提供实时通信。

![Giorgio IM](https://raw.githubusercontent.com/m-finder/giorgio-im/main/docs/images/chat-preview.png)

## 功能

- 用户 IM 资料、头像、签名及在线状态。
- 好友申请、消息盒子、好友分组、移动、删除和拉黑好友。
- 群聊、成员、邀请、退出、群资料、群主与管理员权限、禁言及解散。
- 私聊和群消息、历史记录、图片 / 文件 / 语音上传。
- 已读回执、输入状态、上下线与签名变更通知。
- 可扩展的聊天窗口工具栏。
- 可选敏感词拦截；事务事件记录、游标补取和推送重试由 Socket 包提供。

用户账号、注册、登录和退出由接入应用负责。

本包使用现有用户，不创建账号或登录令牌。

## 环境与安装

需要 PHP 8.1+、Laravel 10、Swoole、mbstring、数据库和 Redis。

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
```

Laravel 自动发现服务提供者。

## 配置现有用户与认证

在发布的 `config/im.php` 中配置：

```php
'user_model' => App\Models\User::class,
'user_name_column' => 'name',
'routes' => [
    'enabled' => true,
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'], // 替换为应用实际使用的认证中间件。
],
```

`user_model` 为 `null` 时读取 `auth.providers.users.model`。模型应为现有的 Eloquent 认证用户模型，使用正整数主键，并与 IM 表使用同一数据库连接。

主键列名、表名及昵称列可以自定义；UUID / ULID 用户主键暂不支持。

首次初始化只创建 IM 资料和默认好友分组。

用户表结构与配置需在首次迁移前确定，已有数据不可通过改配置直接切换用户表。

本包不依赖 Sanctum。上面的认证中间件仅为示例，也可使用自己的 token、JWT 或 Session 认证，确保 `$request->user()` 返回当前用户。

Session 认证需要在路由中间件中启用应用的 Session 与 CSRF 防护；前端请求也需遵循应用的 CSRF 约定。

额外权限检查可继续放入 `auth_middleware`。

## 数据库与服务

配置用户模型后执行迁移，用户表需要先存在：

```bash
php artisan migrate
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

本包自动加载 IM 迁移，创建 `im_profiles`、`im_friend_groups`、`im_friends`、`im_groups`、`im_group_members`、`im_uploads`、`im_messages`、`im_message_reads`、`im_notices`、`im_blocks` 和 `im_events`。需要调整迁移时，可通过 `--tag=im-migrations` 发布后再执行。

配置 `.env`：

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_PREFIX=giorgio-im:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-private-random-secret
IM_SOCKET_URL=ws://127.0.0.1:9501
IM_UPLOAD_DISK=local
IM_SIGNATURE_NOTICES_ENABLED=true
IM_SENSITIVE_WORDS_ENABLED=false
```

分别启动两个常驻进程，并运行应用自己的 HTTP 服务：

```bash
php artisan socket:start
php artisan socket:events
```

本包默认将 Socket 的鉴权和在线回调设置为 IM 实现，关闭客户端直接发送消息，并使用 `im_events` 作为事件表。`configure_socket=false` 时由应用自行配置这些选项。不同 Socket 实例使用不同 Redis 前缀。修改配置或代码后更新配置缓存并重启常驻进程。

公网 HTTP / WebSocket 使用 HTTPS / WSS。服务端推送密钥不要发给浏览器；TLS、反向代理和 Socket 部署见 `wu/giorgio-socket` 文档。本包的 HTTP TLS 中间件默认启用，只有 local/testing 环境允许经过校验的回环明文请求。

## WebSocket 身份适配

认证后的 `POST /socket-ticket` 为当前用户签发加密连接票据，用户 ID 取自 HTTP 认证结果。票据解密及首次连接的到期校验由 Socket 包处理。

默认 `UserIdentity` 不绑定特定登录方式：检查用户存在，并限制连接最长一小时（`socket_session_ttl`）。新连接票据有效期为 60 秒（`socket_ticket_ttl`）。默认实现不会检查应用的登录会话是否已退出或被吊销；需要这类能力时，实现 `SocketIdentity`：

```php
<?php

namespace App\Services;

use GiorgioImSocket\Contracts\SocketIdentity;
use Illuminate\Http\Request;

class ImSocketIdentity implements SocketIdentity
{
    public function claims(Request $request): array
    {
        // 在这里加入经过认证的会话或令牌标识；不要保存原始密码。
        return ['session_id' => $request->attributes->get('verified_session_id')];
    }

    public function resolve(array $claims): ?int
    {
        // ApplicationSessions 是应用自己的服务，应检查会话到期、吊销与用户权限。
        return app(ApplicationSessions::class)->activeUserId(
            $claims['session_id'] ?? null,
            $claims['user_id'] ?? null,
        );
    }
}
```

将 `im.socket_identity` 设置为 `App\Services\ImSocketIdentity::class`，或在容器中绑定该接口。身份适配器在签发票据、建立连接和后续每个客户端帧（包括心跳）时调用。`resolve()` 返回已验证的整数用户 ID；拒绝时返回 `null`。签发端与 Socket 进程必须使用相同的 Laravel `APP_KEY`。

## Vue 组件接入

需安装 [Giorgio IM](https://github.com/m-finder/giorgio-im) 的前端组件，然后引入使用：

```ts
import { createConnectedIM } from 'giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'zh-CN' },
  onError(error, operation) {
    console.error(operation, error)
  },
})
im.open()
// 页面卸载时 im.destroy()
```

`createConnectedIM` 当前内置 Bearer 请求。Session 方案可通过 `createIM` 和自定义请求回调对接自己的 CSRF 流程。`apiUrl` 与 `routes.prefix` 保持一致。前端通过初始化接口获取 IM 数据及 `socketUrl`，获取连接票据后建立 WebSocket，并通过事件游标补取数据。AI 模型和 RTC 音视频信令由应用另行接入。

## HTTP 接口

以下路径相对于默认前缀 `/api/im`。除带签名的附件读取外均需认证，使用 JSON 请求和 `Accept: application/json`。文件上传使用 multipart。

| 方法与路径 | 参数 / 用途 |
| --- | --- |
| `GET /init` | 当前用户、好友分组、群、`init.blocked` 黑名单、`init.recent` 历史私聊、通知、事件游标及 Socket 地址 |
| `POST /socket-ticket` | 当前身份的短期连接票据 |
| `GET /events?after=ID` | 当前用户的事件补取，返回 `events,cursor,hasMore` |
| `PATCH /user` | `username,sign,avatar,status`，按需提交 |
| `GET /users?q=text` | 查找用户，排除自己 |
| `POST /friend-groups` | `groupname` |
| `POST /friend-requests` | `id`，目标用户 |
| `PATCH /friends/{id}/group` | `groupId` |
| `DELETE /friends/{id}` | 移除双向好友关系，保留聊天记录 |
| `GET /blocks` | 当前用户的黑名单 |
| `POST /blocks/{id}` | 拉黑并删除双向好友关系，拒绝双方后续好友申请和私信 |
| `DELETE /blocks/{id}` | 解除当前用户设置的拉黑，不自动恢复好友关系 |
| `POST /notices/{id}` | `action: accept/reject/read`，同意时必须提交自己的 `groupId` |
| `POST /groups` | `groupname,members`，成员为好友 ID 数组 |
| `PATCH /groups/{id}` | `groupname,avatar`，群主或管理员 |
| `DELETE /groups/{id}` | 解散，仅群主 |
| `GET /groups/{id}/members` | 查看成员 |
| `POST /groups/{id}/members` | `members`，邀请好友 |
| `POST /groups/{id}/leave` | 退出群，群主需先解散 |
| `DELETE /groups/{id}/members/{user}` | 踢出成员，按群管理权限校验 |
| `PATCH /groups/{id}/mute` | `muted`，全员禁言 |
| `PATCH /groups/{id}/members/{user}/mute` | `muted`，成员禁言 |
| `PATCH /groups/{id}/members/{user}/admin` | `admin`，仅群主 |
| `GET /messages` | `type,id,before?`，分页历史 |
| `POST /messages` | `type,id,mid,content,attachment?`；`mid` 为 UUID，用于幂等重试 |
| `POST /messages/read` | `type,id,mids`，仅能标记收到的会话消息 |
| `POST /conversations/typing` | `type,id,typing`，不传草稿 |
| `POST /uploads` | `kind: image/file/audio` 和 `file` |
| `GET /files/{id}` | 签名附件 URL |

消息类型 `type` 为 `friend` 或 `group`。附件包含 `kind,url,name,duration?`，必须先上传并校验归属；用户和群头像也必须引用自己的图片上传。认证 API 默认每用户每分钟 240 次，附件读取默认每 IP 每分钟 120 次。

## 配置与扩展

| 配置项 | 默认值 / 说明 |
| --- | --- |
| `user_model` / `user_name_column` | 现有用户模型 / `name` |
| `routes` | 路由开关、前缀、基础与认证中间件 |
| `socket_identity` | `UserIdentity`；可替换应用身份适配器 |
| `socket_ticket_ttl` / `socket_session_ttl` | `60` / `3600` 秒 |
| `configure_socket` | `true`，配置 IM Socket 回调与事件表 |
| `history_limit` | `50`，每页历史消息数 |
| `signature_notices` | `true`，可由 `IM_SIGNATURE_NOTICES_ENABLED` 配置 |
| `upload_max_kb` / `disk` | `10240` / `local`，上传上限与存储磁盘 |
| `sensitive_words` | 默认关闭；支持文件词库及追加词列表 |

敏感词开关为 `IM_SENSITIVE_WORDS_ENABLED`，自定义词库路径为 `IM_SENSITIVE_WORDS_PATH`。开启时词库不可用会拒绝发送，命中返回 422，消息不入库和推送。默认词库来自依赖，生产环境可提供自己的 UTF-8 词库。

`ImService` 可通过容器解析，用于应用自己的 IM 业务扩展。`SocketIdentity` 负责认证适配。包不包含演示页面、注册控制器、默认社区或应用用户模型。

## 测试与许可

安装开发依赖后运行 `composer test`。包内 Testbench 测试使用内存 SQLite、自定义用户表和无 Sanctum 的认证用户，验证路由、迁移、资料、好友、消息、回执及票据适配。

MIT，见 [LICENSE](LICENSE)。
