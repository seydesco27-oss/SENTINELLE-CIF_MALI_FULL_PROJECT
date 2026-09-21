<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
     /**
     * Autorise uniquement les utilisateurs dont le role_id
     * correspond à l'un des IDs fournis à la route.
     *
     * Exemple :
     * ->middleware('role:1,2')
     */
    public function handle(
        Request $request,
        Closure $next,
        ...$roles
    ): Response {
        $user = $request->user();
/*
        |--------------------------------------------------------------------------
        | Utilisateur non authentifié
        |--------------------------------------------------------------------------
        */

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentification requise.',
            ], 401);
        }

        if (!$user->role_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun rôle associé à cet utilisateur.',
            ], 403);
        }


          /*
        |--------------------------------------------------------------------------
        | Vérification du rôle
        |--------------------------------------------------------------------------
        */
        $allowedRoles = array_map('intval', $roles);

        if (!in_array((int) $user->role_id, $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Accès interdit pour ce rôle.',
            ], 403);
        }

        return $next($request);
    }
}
