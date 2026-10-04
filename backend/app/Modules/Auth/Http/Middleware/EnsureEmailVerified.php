<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Booking, payments and publications require a confirmed email (X-01). */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->email_verified_at) {
            return response()->json(['message' => 'Подтвердите email: ссылка отправлена на вашу почту.', 'code' => 'email_not_verified'], 403);
        }

        return $next($request);
    }
}
