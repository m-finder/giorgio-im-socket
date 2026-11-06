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

## Install the local demo

Requires PHP 8.1+ with pdo_sqlite and mbstring, Composer, Node.js/npm and Redis

```bash
curl -fsSL https://raw.githubusercontent.com/m-finder/giorgio-im-socket/main/tools/setup.sh -o /tmp/giorgio-im-setup.sh
bash /tmp/giorgio-im-setup.sh
```

The script first confirms the installation directory: if run from a Laravel root, it asks whether to install there; otherwise, it asks whether to create a Laravel project and where to install it

It installs frontend and backend dependencies, writes the demo page and configuration, creates SQLite, runs migrations and builds the frontend

The frontend package is hosted on GitHub Packages. The script prompts without echoing for a [classic PAT](https://github.com/settings/tokens) with `read:packages` permission and access to the package, then writes the project's `.npmrc`:

```ini
@m-finder:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GIORGIO_IM_NPM_TOKEN}
```

The token is used only for this dependency installation and is not written to a file. Set `GIORGIO_IM_NPM_TOKEN` when installing frontend dependencies manually later

After installation, run one command in each of four terminals at the project root:

```bash
redis-server --bind 127.0.0.1 --port 16380 --save '' --appendonly no
php artisan socket:start
php artisan socket:events
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

Open `http://127.0.0.1:8001/im` and test private and group chats with two accounts

The default HTTP/Socket/Redis ports are `8001/9501/16380`. Set `GIORGIO_IM_HTTP_PORT`, `GIORGIO_IM_SOCKET_PORT` and `GIORGIO_IM_REDIS_PORT` before running the script to change them. Follow the startup commands printed by the script

## Manual installation

Use the following steps to integrate with an existing Laravel application.

### 1. Install dependencies

Requires PHP 8.0.2+, Laravel 9+, mbstring, a database and Redis. Swoole is optional

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
```

### 2. Configure authentication

Set the existing user model and authentication middleware in `config/im.php`:

```php
'user_model' => App\Models\User::class,
'routes' => [
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
],
```

This package does not depend on Sanctum. Use your application's authentication and ensure `$request->user()` returns the current user

User primary keys must be positive integers, and the user and IM tables must use the same database connection

### 3. Database and Socket configuration

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

### 4. Start services

```bash
php artisan socket:start
php artisan socket:events
```

`auto` prefers Swoole outside Windows and uses the native PHP server otherwise

The native Windows driver supports up to 60 concurrent connections

IM configures Socket authentication, presence callbacks and the `im_events` table by default. Disable `configure_socket` to configure them yourself

Use HTTPS/WSS for public access and keep the push secret on the server

*Restart the services after configuration or code changes*

### 5. Frontend integration

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

## Connection identity

Authenticated `POST /api/im/socket-ticket` issues a ticket for the current user. Tickets expire after 60 seconds; connections last up to one hour. The issuer and Socket server must share `APP_KEY`

The default identity adapter checks that the user exists. To validate session or token revocation, implement `GiorgioImSocket\Contracts\SocketIdentity` and set `im.socket_identity`:

```php
public function claims(Request $request): array;
public function resolve(array $claims): ?int;
```

## HTTP API

All routes require authentication except signed file reads. Use JSON with `Accept: application/json`; uploads use multipart data

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/init` | User, friends, groups, notices, blocks, event cursor and Socket URL |
| `POST` | `/socket-ticket` | Issue connection ticket |
| `GET` | `/events?after=ID` | Fetch missed events |
| `PATCH` | `/user` | Update profile |
| `GET` | `/users?q=text` | Search users |
| `POST` | `/friend-groups` | Create friend group |
| `POST` | `/friend-requests` | Request friendship |
| `PATCH` / `DELETE` | `/friends/{id}/group`, `/friends/{id}` | Move or remove friend |
| `GET` / `POST` / `DELETE` | `/blocks`, `/blocks/{id}` | List, block or unblock |
| `POST` | `/notices/{id}` | Resolve request or notice |
| `POST` / `PATCH` / `DELETE` | `/groups`, `/groups/{id}` | Create, update or disband group |
| `GET` / `POST` / `DELETE` | `/groups/{id}/members` | Manage group members |
| `PATCH` | `/groups/{id}/mute`, `/groups/{id}/members/{user}/mute` | Mute group or member |
| `PATCH` | `/groups/{id}/members/{user}/admin` | Set administrator |
| `GET` / `POST` | `/messages` | History and send |
| `POST` | `/messages/read` | Read receipt |
| `POST` | `/conversations/typing` | Typing state |
| `POST` | `/uploads` | Upload image, file or audio |
| `GET` | `/files/{id}` | Read signed attachment |

The `type` for private and group chats is `friend` and `group`, respectively. Supply a UUID `mid` for idempotent retries and upload attachments before sending
Send multiple images with text in one message using an `attachments` array and `content`. Single attachments continue to use `attachment`

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

MIT, see [LICENSE](LICENSE)
