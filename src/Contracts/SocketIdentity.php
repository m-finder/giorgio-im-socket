<?php

namespace GiorgioImSocket\Contracts;

use Illuminate\Http\Request;

/** Bridge application authentication to encrypted Socket connection tickets. */
interface SocketIdentity
{
    /** Additional claims for the currently authenticated HTTP request. */
    public function claims(Request $request): array;

    /** Revalidate identity on connect and every subsequent frame; null rejects the operation. */
    public function resolve(array $claims): ?int;
}
