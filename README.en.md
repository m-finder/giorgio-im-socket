# Giorgio IM Socket

[简体中文](README.md) | [English](README.en.md)

A Laravel backend integration for the Vue / TypeScript `giorgio-im` component, powered by `wu/giorgio-socket`.

## Features

- IM profiles, avatars, signatures and presence.
- Friend requests, notices, friend categories, moving and removing friends.
- Groups, invitations, membership, profiles, owner and administrator permissions, muting and dismissal.
- Private and group messages, history and image / file / voice uploads.
- Read receipts, typing state and presence / signature notices.
- Optional sensitive-word checks, with transactional events, cursor catch-up and push retries provided by Socket.

The application owns user accounts, registration, login and logout. This package uses existing users and does not create accounts or login tokens, send automatic friend requests or join a predefined community.

## Requirements and installation

Requires PHP 8.1+, Laravel 10, Swoole, mbstring, a database and Redis. The selected Laravel version's own PHP requirements also apply. Integration tests currently use Laravel 10. Configure database and Redis clients through Laravel.

```bash
composer require wu/giorgio-im-socket
php artisan vendor:publish --provider="GiorgioImSocket\Providers\ImServiceProvider" --tag=im-config
```

Composer installs `wu/giorgio-socket ^2.0` and the sensitive-word dependency. Laravel discovers the service provider automatically. The installation command above assumes the package is available in a configured Composer registry.

Source repository: [m-finder/giorgio-im-socket](https://github.com/m-finder/giorgio-im-socket). Before it is listed on Packagist, or to install directly from GitHub, merge these repositories into your application's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/m-finder/giorgio-im-socket" },
    { "type": "vcs", "url": "https://github.com/m-finder/giorgio-socket" }
  ]
}
```

Then run `composer require wu/giorgio-im-socket:^1.0`. For local package development, a path repository is also supported. Registration, login and the demo application are not included in this distribution.

## Existing users and authentication

Edit the published `config/im.php`:

```php
'user_model' => App\Models\User::class,
'user_name_column' => 'name',
'routes' => [
    'enabled' => true,
    'prefix' => 'api/im',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'], // Use your application's authentication middleware.
],
```

When `user_model` is `null`, the package reads `auth.providers.users.model`. Use your existing Eloquent authentication model with positive integer keys on the same database connection as the IM tables. Table, primary key and display-name columns can be customised. UUID / ULID user keys are not supported. Initialisation creates an IM profile and a default friend category only. Set the user table and model before the first migration; changing configuration alone cannot move existing IM data to another user table.

Sanctum is not a dependency. The middleware above is an example: use your token, JWT or Session authentication and ensure `$request->user()` returns the current user. Session authentication requires the application's session and CSRF middleware, with frontend requests following its CSRF conventions. Add application permission checks to `auth_middleware` as needed.

## Database and processes

Configure the user model before migrating. Its table must already exist:

```bash
php artisan migrate
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

The package loads migrations for `im_profiles`, `im_friend_groups`, `im_friends`, `im_groups`, `im_group_members`, `im_uploads`, `im_messages`, `im_message_reads`, `im_notices`, `im_blocks` and `im_events`. Publish with `--tag=im-migrations` before migration if customisation is needed.

Configure `.env`:

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

Start these as separate long-running processes alongside your application's HTTP service:

```bash
php artisan socket:start
php artisan socket:events
```

By default, the package configures Socket's IM authenticator and presence callback, disables direct client message frames and uses `im_events` for durable events. Set `configure_socket=false` to configure those options yourself. Use different Redis prefixes for separate Socket instances. Refresh cached configuration and restart long-running processes after code or configuration changes.

Use HTTPS / WSS for public access and keep the push secret on trusted servers. See `wu/giorgio-socket` documentation for TLS and reverse proxies. The package's HTTP TLS middleware is enabled by default; validated plaintext loopback access is allowed only in local/testing environments.

## WebSocket identity adapter

Authenticated `POST /socket-ticket` issues an encrypted connection ticket for the current HTTP user. Socket decrypts the ticket and checks expiry for new connections.

The default `UserIdentity` checks that the user exists and limits connection lifetime to one hour (`socket_session_ttl`). New connection tickets expire after 60 seconds (`socket_ticket_ttl`). This default does not check whether an application login session has logged out or been revoked. Implement `SocketIdentity` when that is required:

```php
<?php

namespace App\Services;

use GiorgioImSocket\Contracts\SocketIdentity;
use Illuminate\Http\Request;

class ImSocketIdentity implements SocketIdentity
{
    public function claims(Request $request): array
    {
        // Add an authenticated session or token identifier; never store a raw password.
        return ['session_id' => $request->attributes->get('verified_session_id')];
    }

    public function resolve(array $claims): ?int
    {
        // ApplicationSessions is your own service for expiry, revocation and user permission checks.
        return app(ApplicationSessions::class)->activeUserId(
            $claims['session_id'] ?? null,
            $claims['user_id'] ?? null,
        );
    }
}
```

Set `im.socket_identity` to `App\Services\ImSocketIdentity::class`, or bind the interface in the container. The adapter is called at ticket issuance, connection and every subsequent client frame, including heartbeats. `resolve()` returns a verified integer user ID or `null` to reject. The issuer and Socket process must share the same Laravel `APP_KEY`.

## Vue integration

Authenticate through your application, then provide the existing credentials to the component:

```ts
import { createConnectedIM } from 'giorgio-im'

const im = await createConnectedIM({
  token: () => accessToken,
  apiUrl: '/api/im',
  widget: { locale: 'en' },
  onError(error, operation) {
    console.error(operation, error)
  },
})
im.open()
// Call im.destroy() when unmounting.
```

`createConnectedIM` currently sends Bearer credentials. For Session authentication, use `createIM` with application request callbacks that follow your CSRF flow. Keep `apiUrl` consistent with `routes.prefix`. Initialisation returns IM data and `socketUrl`; the component obtains a ticket, connects through WebSocket and catches up using event cursors. AI models and RTC signalling require application integration.

## HTTP API

Paths below are relative to `/api/im`. All routes except signed file reads require authentication. Use JSON and `Accept: application/json`; uploads use multipart data.

| Method and path | Input / purpose |
| --- | --- |
| `GET /init` | User, friend categories, groups, `init.blocked`, historical private contacts in `init.recent`, notices, cursor and Socket URL |
| `POST /socket-ticket` | Short-lived connection ticket for the current identity |
| `GET /events?after=ID` | Current user's events: `events,cursor,hasMore` |
| `PATCH /user` | Optional `username,sign,avatar,status` |
| `GET /users?q=text` | Search users, excluding self |
| `POST /friend-groups` | `groupname` |
| `POST /friend-requests` | Target user `id` |
| `PATCH /friends/{id}/group` | `groupId` |
| `DELETE /friends/{id}` | Remove mutual friendship and keep chat history |
| `GET /blocks` | Current user’s blocklist |
| `POST /blocks/{id}` | Block, remove mutual friendship, reject new requests and private messages |
| `DELETE /blocks/{id}` | Unblock without restoring friendship |
| `POST /notices/{id}` | `action: accept/reject/read`; acceptance requires an owned `groupId` |
| `POST /groups` | `groupname,members`, an array of friend IDs |
| `PATCH /groups/{id}` | `groupname,avatar`; owner or administrator |
| `DELETE /groups/{id}` | Dismiss; owner only |
| `GET /groups/{id}/members` | List members |
| `POST /groups/{id}/members` | `members`, friend IDs to invite |
| `POST /groups/{id}/leave` | Leave; owners must dismiss instead |
| `DELETE /groups/{id}/members/{user}` | Remove member, subject to management permissions |
| `PATCH /groups/{id}/mute` | `muted`, group-wide mute |
| `PATCH /groups/{id}/members/{user}/mute` | `muted`, member mute |
| `PATCH /groups/{id}/members/{user}/admin` | `admin`, owner only |
| `GET /messages` | `type,id,before?`, paginated history |
| `POST /messages` | `type,id,mid,content,attachment?`; UUID `mid` supports idempotent retries |
| `POST /messages/read` | `type,id,mids`; received messages in the specified conversation only |
| `POST /conversations/typing` | `type,id,typing`; no draft text |
| `POST /uploads` | `kind: image/file/audio` and `file` |
| `GET /files/{id}` | Signed attachment URL |

Message `type` is `friend` or `group`. Attachments contain `kind,url,name,duration?` and must be uploaded first with ownership checks. User and group avatars must also reference the current user's image upload. Default limits are 240 authenticated requests per user per minute and 120 file reads per IP per minute.

## Configuration and extension

| Option | Default / purpose |
| --- | --- |
| `user_model` / `user_name_column` | Existing user model / `name` |
| `routes` | Route registration switch, prefix, base and authentication middleware |
| `socket_identity` | `UserIdentity`; replace with your identity adapter |
| `socket_ticket_ttl` / `socket_session_ttl` | `60` / `3600` seconds |
| `configure_socket` | `true`, configures IM Socket callbacks and the event table |
| `history_limit` | `50` messages per history page |
| `signature_notices` | `true`, configurable with `IM_SIGNATURE_NOTICES_ENABLED` |
| `upload_max_kb` / `disk` | `10240` / `local`, upload size and storage disk |
| `sensitive_words` | Disabled by default; file dictionary and additional word list |

Use `IM_SENSITIVE_WORDS_ENABLED` to enable filtering and `IM_SENSITIVE_WORDS_PATH` for a custom dictionary. Missing dictionaries reject sending when filtering is enabled. Matches return 422 without storing or pushing the message. The dependency supplies a basic dictionary; production applications can provide their own UTF-8 list.

Resolve `ImService` from the container for application-specific IM extensions. `SocketIdentity` adapts authentication. The package contains no demo page, registration controller, predefined community or application user model.

## Tests and licence

After installing development dependencies, run `composer test`. Testbench tests use in-memory SQLite, a custom user table and a user model without Sanctum to exercise routes, migrations, profiles, friendships, messages, receipts and ticket adapters.

MIT; see [LICENSE](LICENSE).

### Deletion and blocking

Blocking atomically removes mutual friendship and rejects pending requests in both directions. `user.blocked` updates the owner’s connections; the other user receives only `friend.removed`. Unblocking emits `user.unblocked` and never restores friendship. Users can only list and remove their own blocks.

Messages survive deletion and blocking. Only actual private conversation participants can continue reading its history. Sending, read receipts and typing still require friendship. Shared group messages remain visible; the widget greys blocked senders. Run `php artisan migrate` after upgrading to create the blocklist table.
