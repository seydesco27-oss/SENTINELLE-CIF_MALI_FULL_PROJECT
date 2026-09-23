<?php

namespace App\Http\Middleware;

use App\Support\AccessProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentification requise.'], 401);
        }

        if (! AccessProfile::allows($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Permission insuffisante.',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
