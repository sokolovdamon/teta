<?php

namespace App\Modules\Rbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: role:psychologist,supervisor */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless($user->hasRole(...$roles) || $user->isSuperAdmin(), 403, 'Раздел недоступен для вашей роли.');

        return $next($request);
    }
}
