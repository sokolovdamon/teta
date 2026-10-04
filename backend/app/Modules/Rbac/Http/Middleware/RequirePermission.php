<?php

namespace App\Modules\Rbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: permission:admin.users.view (any of several, separated by "|"). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $any = collect(explode('|', $permissions))->contains(fn ($p) => $user->hasPermission($p));
        abort_unless($any, 403, 'Недостаточно прав для этого действия.');

        return $next($request);
    }
}
