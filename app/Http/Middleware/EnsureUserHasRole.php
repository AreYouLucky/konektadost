<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $allowedRoles = array_map(
            fn (string $role): string => strtolower(trim($role)),
            $roles
        );

        $userRole = strtolower((string) $user->role);

        if (in_array($userRole, $allowedRoles, true)) {
            return $next($request);
        }

        $dashboardRoute = $user->dashboardRoute();

        if (Route::has($dashboardRoute)) {
            return redirect()->route($dashboardRoute);
        }

        return redirect()->route('home');
    }
}
