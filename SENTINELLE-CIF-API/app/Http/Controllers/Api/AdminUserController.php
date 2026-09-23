<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'u.agency_id')
            ->leftJoin('caisses as c', 'c.id', '=', 'u.caisse_id')
            ->select([
                'u.id', 'u.username', 'u.first_name', 'u.last_name', 'u.email',
                'u.job_title', 'u.role_id', 'u.agency_id', 'u.caisse_id',
                'u.scope_level', 'u.is_active', 'u.created_at',
                'r.name as role_name', 'a.code as agency_code', 'a.name as agency_name',
                'c.code as caisse_code', 'c.name as caisse_name',
            ])
            ->orderBy('r.id')->orderBy('u.username');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($builder) use ($search): void {
                $builder->where('u.username', 'like', "%{$search}%")
                    ->orWhere('u.first_name', 'like', "%{$search}%")
                    ->orWhere('u.last_name', 'like', "%{$search}%")
                    ->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        foreach (['caisse_id', 'agency_id'] as $filter) {
            if ($request->filled($filter)) $query->where('u.'.$filter, (int) $request->query($filter));
        }
        return response()->json([
            'success' => true,
            'data' => $query->get(),
            'options' => [
                'roles' => DB::table('roles')->whereIn('id', [1, 2, 3, 4])->orderBy('id')->get(['id', 'name', 'description']),
                'caisses' => DB::table('caisses')->orderBy('name')->get(['id', 'code', 'name']),
                'agencies' => DB::table('agencies as a')->join('caisses as c', 'c.id', '=', 'a.caisse_id')
                    ->orderBy('c.name')->orderBy('a.name')
                    ->get(['a.id', 'a.code', 'a.name', 'a.caisse_id', 'c.name as caisse_name']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $assignment = $this->normalizeAssignment($validated);
        $user = User::query()->create($this->values($validated, $assignment));

        return response()->json([
            'success' => true,
            'message' => 'Compte utilisateur créé.',
            'data' => ['id' => $user->id, 'username' => $user->username],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $validated = $this->validatePayload($request, $user);
        $assignment = $this->normalizeAssignment($validated);

        if ($request->user()->id === $user->id && array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            return response()->json(['success' => false, 'message' => 'Vous ne pouvez pas désactiver votre propre session.'], 422);
        }

        $user->fill($this->values($validated, $assignment, $user))->save();
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json(['success' => true, 'message' => 'Compte utilisateur mis à jour.']);
    }

    private function validatePayload(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:100', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:255'],
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'job_title' => ['nullable', 'string', 'max:150'],
            'role_id' => ['required', 'integer', Rule::in([1, 2, 3, 4]), Rule::exists('roles', 'id')],
            'caisse_id' => ['nullable', 'integer', Rule::exists('caisses', 'id')],
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')],
            'scope_level' => ['required', Rule::in(['PLATFORM', 'CAISSE', 'AGENCY'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function normalizeAssignment(array $values): array
    {
        $roleId = (int) $values['role_id'];
        if ($roleId === AccessProfile::ADMIN) {
            if ($values['scope_level'] !== 'PLATFORM') throw ValidationException::withMessages(['scope_level' => 'ADMIN est réservé à la plateforme SaaS.']);
            return ['agency_id' => null, 'caisse_id' => null, 'scope_level' => 'PLATFORM'];
        }

        $agencyId = (int) ($values['agency_id'] ?? 0);
        $agencyCaisse = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('caisse_id') : null;
        $caisseId = (int) ($values['caisse_id'] ?? $agencyCaisse ?? 0);
        if (!$caisseId) throw ValidationException::withMessages(['caisse_id' => 'Choisissez une caisse.']);
        if ($agencyId && (int) $agencyCaisse !== $caisseId) throw ValidationException::withMessages(['agency_id' => 'Cette agence ne dépend pas de la caisse choisie.']);
        $allowedScopes = match ($roleId) {
            AccessProfile::COMPLIANCE_OFFICER, AccessProfile::SUPERVISOR => ['CAISSE', 'AGENCY'],
            default => ['AGENCY'],
        };
        abort_unless(in_array($values['scope_level'], $allowedScopes, true), 422, 'Périmètre incompatible avec ce rôle.');
        if ($values['scope_level'] === 'AGENCY' && !$agencyId) throw ValidationException::withMessages(['agency_id' => 'Choisissez une agence pour le périmètre agence.']);

        return ['agency_id' => $agencyId ?: null, 'caisse_id' => $caisseId, 'scope_level' => $values['scope_level']];
    }

    private function values(array $values, array $assignment, ?User $user = null): array
    {
        $result = [
            'username' => trim($values['username']),
            'first_name' => trim($values['first_name']),
            'last_name' => trim($values['last_name']),
            'email' => ! empty($values['email']) ? mb_strtolower(trim($values['email'])) : null,
            'job_title' => ! empty($values['job_title']) ? trim($values['job_title']) : null,
            'role_id' => (int) $values['role_id'],
            'agency_id' => $assignment['agency_id'],
            'caisse_id' => $assignment['caisse_id'],
            'scope_level' => $assignment['scope_level'],
            'is_active' => (bool) ($values['is_active'] ?? $user?->is_active ?? true),
            'profile_updated_at' => now(),
        ];
        if (! empty($values['password'])) {
            $result['password_hash'] = Hash::make($values['password']);
        }
        return $result;
    }
}
