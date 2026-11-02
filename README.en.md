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

Laravel backend for Giorgio IM, with friendship, group, messaging and real-time event APIs

<p align="center"><img src="https://repository-images.githubusercontent.com/1440670842/afcc1279-de3b-4152-99af-2892e552f1f4"></p>

## Related packages

- [Giorgio IM](https://github.com/m-finder/giorgio-im): Vue 3 + TypeScript instant messaging component
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket): Laravel instant messaging backend
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket): Laravel WebSocket infrastructure package

## Install

Requires PHP 8.0.2+, Laravel 9+, mbstring, a database and Redis. Swoole is optional

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
```

To upgrade the package and apply pending migrations:

```bash
composer update wu/giorgio-im-socket
php artisan migrate
```

## Configure

Set the existing user model and authentication middleware in `config/im.php`:

```php
'user_model' => App\Models\User::class,
'routes' => [
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
],
```

Sanctum is optional. Use your application's authentication and ensure `$request->user()` returns the current user. User keys must be positive integers, and IM and user tables must share a database connection

Create the user table, then migrate and publish the Socket configuration:

```bash
php artisan migrate
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

Set `.env`:

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

Start the services:

```bash
php artisan socket:start
php artisan socket:events
```

`auto` prefers Swoole outside Windows and uses the native PHP server otherwise. The native Windows driver supports up to 60 concurrent connections. IM configures Socket authentication, presence and the `im_events` table by default; set `configure_socket=false` to manage these yourself

Use HTTPS/WSS publicly and keep the push secret on the server. Restart services after configuration or code changes

## Connection identity

Authenticated `POST /api/im/socket-ticket` issues a ticket for the current user. Tickets expire after 60 seconds; connections last up to one hour. The issuer and Socket server must share `APP_KEY`

The default identity adapter checks that the user exists. To validate session or token revocation, implement `GiorgioImSocket\Contracts\SocketIdentity` and set `im.socket_identity`:

```php
public function claims(Request $request): array;
public function resolve(array $claims): ?int;
```

## Frontend

```ts
import { createConnectedIM } from 'giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'en' },
})
im.open()
// Call im.destroy() when unmounting
```

Keep `apiUrl` consistent with `routes.prefix`. The component gets the Socket URL from `/init`; AI and RTC signalling require application integration

## HTTP API

All routes require authentication except signed file reads. Use JSON with `Accept: application/json`; uploads use multipart data

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/init` | User, contacts, groups, notices, blocks, event cursor and Socket URL |
| `POST` | `/socket-ticket` | Issue connection ticket |
| `GET` | `/events?after=ID` | Fetch missed events |
| `PATCH` | `/user` | Update profile |
| `GET` | `/users?q=text` | Search users |
| `POST` | `/friend-groups` | Create friend category |
| `POST` | `/friend-requests` | Request friendship |
| `PATCH` / `DELETE` | `/friends/{id}/group`, `/friends/{id}` | Move or remove friend |
| `GET` / `POST` / `DELETE` | `/blocks`, `/blocks/{id}` | List, block or unblock |
| `POST` | `/notices/{id}` | Resolve request or notice |
| `POST` | `/groups` | Create group |
| `PATCH` / `DELETE` | `/groups/{id}` | Update or dismiss group |
| `GET` / `POST` | `/groups/{id}/members` | List or invite members |
| `POST` | `/groups/{id}/leave` | Leave group |
| `DELETE` | `/groups/{id}/members/{user}` | Remove member |
| `PATCH` | `/groups/{id}/mute`, `/groups/{id}/members/{user}/mute` | Mute group or member |
| `PATCH` | `/groups/{id}/members/{user}/admin` | Set administrator |
| `GET` / `POST` | `/messages` | History and send |
| `POST` | `/messages/read` | Read receipt |
| `POST` | `/conversations/typing` | Typing state |
| `POST` | `/uploads` | Upload image, file or audio |
| `GET` | `/files/{id}` | Read signed attachment |

Message `type` is `friend` or `group`. Supply a UUID `mid` for idempotent retries. Upload attachments before sending

## Other settings

| Setting | Default |
| --- | --- |
| `user_model` | `auth.providers.users.model` |
| `socket_identity` | `UserIdentity` |
| `socket_ticket_ttl` / `socket_session_ttl` | 60 / 3600 seconds |
| `history_limit` | 50 messages |
| `upload_max_kb` / `disk` | 10240 / `local` |
| `IM_SIGNATURE_NOTICES_ENABLED` | `true` |
| `IM_SENSITIVE_WORDS_ENABLED` | `false` |

Set `IM_SENSITIVE_WORDS_PATH` for a custom dictionary

## Blocking

- Blocking removes mutual friendship, rejects pending requests and prevents new private messages while keeping history
- Unblocking does not restore friendship

Run tests with `composer test`

MIT, see [LICENSE](LICENSE)
