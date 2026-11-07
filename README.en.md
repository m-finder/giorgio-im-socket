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
- [Giorgio IM React](https://github.com/m-finder/giorgio-im-react): React + TypeScript instant messaging component
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket): Laravel instant messaging backend
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket): Laravel WebSocket infrastructure package

## Quick demo package

Download the `im-test-*` standalone executable for your platform (or the `.exe` for Windows) from the [Releases page](https://github.com/m-finder/giorgio-im-socket/releases). Run it and open the demo page shown in the terminal. No PHP, Node.js, Composer or Redis installation is needed; run it with `--help` for port options. A `.phar` version is also available and requires a compatible PHP installation.

## Install the local demo

The demo installer requires PHP 8.0.2+ (`pdo_sqlite` and `mbstring`). New projects use Laravel 9; existing Laravel 9/10 projects must meet their own dependency requirements. It also needs Composer, Node.js/npm and Redis. The script installs the frontend and backend, configures SQLite and builds the demo page using the native PHP Socket driver.

```bash
curl -fsSL https://raw.githubusercontent.com/m-finder/giorgio-im-socket/main/tools/setup.sh -o /tmp/giorgio-im-setup.sh
bash /tmp/giorgio-im-setup.sh
```

Follow the prompts to install into the current Laravel project or create a project in an empty directory. Each run asks for a [GitHub classic PAT](https://github.com/settings/tokens) with `read:packages` permission and access to the frontend package. Input is hidden.

After installation, open four terminals at the project root and run one command in each:

```bash
redis-server --bind 127.0.0.1 --port 16380 --save '' --appendonly no
php artisan socket:start
php artisan socket:events
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

Open `http://127.0.0.1:8001/im` and register two accounts to try private and group chats. For custom ports, use the startup commands and address printed by the script.

<details>
<summary>Existing projects, ports and file changes</summary>

- Select an existing project: `bash /tmp/giorgio-im-setup.sh /path/to/project`. Show usage: `bash /tmp/giorgio-im-setup.sh --help`.
- HTTP, Socket and Redis ports default to `8001`, `9501` and `16380`. Set `GIORGIO_IM_HTTP_PORT`, `GIORGIO_IM_SOCKET_PORT` and `GIORGIO_IM_REDIS_PORT` before running the script to override them.
- The script writes the demo page, User model, routes and configuration, then updates `.env` to use `storage/app/im-demo/database.sqlite`. Changed existing files are backed up under `storage/app/im-demo-backups/`. Rerunning preserves database contents and valid secrets.
- The token is used only for this dependency installation and is never written to project files. `.npmrc` uses the `${GIORGIO_IM_NPM_TOKEN}` placeholder; set that environment variable before installing frontend dependencies manually later.

</details>

## Manual installation

For an existing Laravel project. Requires PHP 8.0.2+, Laravel 9+, compatible project dependencies, `mbstring`, a database and Redis.

### 1. Install and publish configuration

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

### 2. Configure users and authentication

Set the user model and your application's authentication middleware in `config/im.php`:

```php
'user_model' => App\Models\User::class,
'routes' => [
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
],
```

`auth:sanctum` is an example and requires your application to set up Sanctum. Replace it with your own authentication if needed. After authentication, `$request->user()` must return the current user. User keys must be positive integers, and the user and IM tables must share a database connection.

### 3. Configure and start services

Set Socket values in `.env` and use your application's Redis configuration:

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_DRIVER=auto
SOCKET_PREFIX=giorgio-im:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-private-random-secret
IM_SOCKET_URL=ws://127.0.0.1:9501
```

Ensure the user table exists, then run migrations:

```bash
php artisan migrate
```

Start Socket and event delivery in separate terminals:

```bash
php artisan socket:start
php artisan socket:events
```

These addresses are for local development. Use HTTPS/WSS for public access and keep the push secret on the server. Restart both services after code or configuration changes.

<details>
<summary>Drivers and optional settings</summary>

- `auto` prefers Swoole outside Windows and uses native PHP otherwise. The native Windows driver supports up to 60 concurrent connections.
- IM configures Socket authentication, presence callbacks and the `im_events` table by default. Disable `im.configure_socket` to configure them yourself.
- See “Other settings” below for uploads, signature notices and sensitive word filtering.

</details>

### 4. Connect the frontend

Install the component using the [Vue](https://github.com/m-finder/giorgio-im/blob/main/README.en.md#install) or [React](https://github.com/m-finder/giorgio-im-react/blob/main/README.en.md#install) instructions, then connect with an access token obtained at login:

```ts
import { createConnectedIM } from '@m-finder/giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'en' },
})
im.open()
// Call im.destroy() when unmounting
```

For React, import from `@m-finder/giorgio-im-react` instead.

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
| `PATCH` | `/groups/{id}/notifications` | Set the current user's group notification preference with `{ muted: boolean }` |
| `PATCH` | `/groups/{id}/members/{user}/admin` | Set administrator |
| `GET` / `POST` | `/messages` | History and send |
| `POST` | `/messages/read` | Read receipt |
| `POST` | `/conversations/typing` | Typing state |
| `POST` | `/uploads` | Upload image, file or audio |
| `GET` | `/files/{id}` | Read signed attachment |

The `type` for private and group chats is `friend` and `group`, respectively. Supply a UUID `mid` for idempotent retries and upload attachments before sending
Send multiple images with text in one message using an `attachments` array and `content`. Single attachments continue to use `attachment`

Group messages support `mentions: { id, username, start, end }[]`. Offsets use UTF-16 indices into the content, with an exclusive end. The server validates group membership, text ranges and overlapping spans.

Group notification muting is a personal preference, independent of posting restrictions. Group contacts returned by initialisation and the preference endpoint include `notificationsMuted`; changes sync through `group.updated` only to the current user. All messages are still delivered, allowing the frontend to retain unread counts and alert on mentions of the current user. Run `php artisan migrate` after upgrading to add the personal group notification field.

To quote a private or group message, submit `replyTo: { mid, fromid }`. The server only accepts visible messages from the current conversation and returns a snapshot with `username` and `content`, plus `attachment` or `attachments` for image thumbnails and captions. Both `mentions` and `replyTo` are included in live events and history and must remain unchanged when retrying the same `mid`. Run `php artisan migrate` after upgrading to add the message context fields.

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
