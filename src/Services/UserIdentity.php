<?php

namespace GiorgioImSocket\Services;

use GiorgioImSocket\Contracts\SocketIdentity;
use Illuminate\Http\Request;

/** Authentication-neutral default: existing user plus a bounded connection lifetime. */
class UserIdentity implements SocketIdentity
{
    public function claims(Request $request): array
    {
        return ['valid_until' => now()->addSeconds((int) config('im.socket_session_ttl', 3600))->timestamp];
    }

    public function resolve(array $claims): ?int
    {
        if (! is_int($claims['user_id'] ?? null) || $claims['user_id'] < 1
            || ! is_int($claims['valid_until'] ?? null) || $claims['valid_until'] <= now()->timestamp) {
            return null;
        }

        return app(Users::class)->query()->whereKey($claims['user_id'])->exists() ? $claims['user_id'] : null;
    }
}
