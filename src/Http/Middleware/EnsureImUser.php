<?php

namespace GiorgioImSocket\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureImUser
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless($id !== null, 401);
        abort_unless(ctype_digit((string) $id) && (int) $id > 0 && (string) (int) $id === (string) $id, 422, 'Giorgio IM requires a positive integer user ID.');

        return $next($request);
    }
}
