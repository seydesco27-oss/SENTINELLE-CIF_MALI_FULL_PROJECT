<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Connexion utilisateur.
     *
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:100',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
            ],
        ]);

        $user = User::query()
            ->with([
                'agency.caisse',
                'role',
            ])
            ->where('username', $validated['username'])
            ->first();

        if (!$user || !Hash::check(
            $validated['password'],
            $user->password_hash
        )) {
            throw ValidationException::withMessages([
                'username' => [
                    'Identifiants invalides.'
                ],
            ]);
        }

        /*
         * Révocation des anciens tokens du même utilisateur.
         *
         * Cela évite d'accumuler inutilement les sessions API.
         */
        $user->tokens()->delete();

        /*
         * Token principal de l'application Flutter.
         */
        $token = $user->createToken(
            'digiaml-flutter'
        )->plainTextToken;

        return response()->json([
            'success' => true,

            'message' => 'Connexion réussie.',

            'data' => [
                'token' => $token,

                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,

                    'agency' => $user->agency ? [
                        'id' => $user->agency->id,
                        'code' => $user->agency->code,
                        'name' => $user->agency->name,
                        'city' => $user->agency->city,
                        'caisse' => $user->agency->caisse ? [
                            'id' => $user->agency->caisse->id,
                            'code' => $user->agency->caisse->code,
                            'name' => $user->agency->caisse->name,
                            'city' => $user->agency->caisse->city,
                            'country' => $user->agency->caisse->country,
                            'status' => $user->agency->caisse->status,
                        ] : null,
                    ] : null,

                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'description' => $user->role->description,
                    ] : null,

                    'created_at' => $user->created_at,
                ],
            ],
        ]);
    }

    /**
     * Utilisateur actuellement authentifié.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->load([
            'agency.caisse',
            'role',
        ]);

        return response()->json([
            'success' => true,

            'data' => [
                'id' => $user->id,
                'username' => $user->username,

                'agency' => $user->agency ? [
                    'id' => $user->agency->id,
                    'code' => $user->agency->code,
                    'name' => $user->agency->name,
                    'city' => $user->agency->city,
                ] : null,

                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'description' => $user->role->description,
                ] : null,

                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Déconnexion.
     *
     * DELETE /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * On supprime uniquement le token utilisé
         * pour cette requête.
         */
        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            $currentToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie.',
        ]);
    }


    public function changePassword(Request $request)
{
    $request->validate([
        'current_password' => [
            'required',
            'string',
        ],

        'new_password' => [
            'required',
            'string',
            'min:12',
            'max:255',
        ],
    ]);

    $user = $request->user();

    /*
    |--------------------------------------------------------------------------
    | Vérification du mot de passe actuel
    |--------------------------------------------------------------------------
    */

    if (!Hash::check(
        $request->current_password,
        $user->password_hash
    )) {
        return response()->json([
            'success' => false,
            'message' => 'Le mot de passe actuel est incorrect.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Empêcher la réutilisation immédiate
    |--------------------------------------------------------------------------
    */

    if (Hash::check(
        $request->new_password,
        $user->password_hash
    )) {
        return response()->json([
            'success' => false,
            'message' => 'Le nouveau mot de passe doit être différent de l’ancien.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Nouveau hash
    |--------------------------------------------------------------------------
    */

    $user->password_hash = Hash::make(
        $request->new_password
    );

    $user->save();

    /*
    |--------------------------------------------------------------------------
    | Révocation des tokens existants
    |--------------------------------------------------------------------------
    |
    | Toutes les sessions/tokens précédents sont invalidés.
    | L'utilisateur devra se reconnecter.
    |
    */

    $user->tokens()->delete();

    return response()->json([
        'success' => true,
        'message' => 'Mot de passe modifié avec succès. Veuillez vous reconnecter.',
    ]);
}


}
