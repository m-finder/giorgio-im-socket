<?php

namespace GiorgioImSocket\Services;

use GiorgioImSocket\Contracts\SocketIdentity;
use GiorgioSocket\Services\ConnectionTicket;

/** Decryption belongs to Giorgio Socket; login/session validation belongs to the configured adapter. */
class SocketAuthenticator
{
    public function __invoke(array $packet, bool $established = false): ?int
    {
        if (! is_string($packet['token'] ?? null)) {
            return null;
        }
        $claims = app(ConnectionTicket::class)->claims($packet['token'], $established);
        if ($claims === null) {
            return null;
        }
        $id = app(SocketIdentity::class)->resolve($claims);

        return $id !== null && $id === ($claims['user_id'] ?? null) ? $id : null;
    }
}
