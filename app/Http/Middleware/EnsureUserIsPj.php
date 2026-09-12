<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPj
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isPj()) {
            abort(403, 'Hanya PJ mata kuliah atau Admin yang dapat mengakses.');
        }

        return $next($request);
    }
}
