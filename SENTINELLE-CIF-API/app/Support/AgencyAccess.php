<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgencyAccess
{
    public const PLATFORM = 'PLATFORM';
    public const CAISSE = 'CAISSE';
    public const AGENCY = 'AGENCY';
    public const PORTFOLIO = 'PORTFOLIO';

    public static function scopeFor(User $user): array
    {
        $roleId = (int) $user->role_id;
        $explicit = strtoupper((string) ($user->scope_level ?? ''));
        $allowedTypes = match ($roleId) {
            AccessProfile::ADMIN => [self::PLATFORM],
            AccessProfile::COMPLIANCE_OFFICER => [self::CAISSE, self::AGENCY],
            AccessProfile::SUPERVISOR => [self::AGENCY, self::CAISSE],
            AccessProfile::ACCOUNT_MANAGER => [self::PORTFOLIO],
            default => [self::AGENCY],
        };
        $type = in_array($explicit, $allowedTypes, true) ? $explicit : $allowedTypes[0];
        $agencyId = $user->agency_id !== null ? (int) $user->agency_id : null;
        $caisseId = isset($user->caisse_id) && $user->caisse_id !== null
            ? (int) $user->caisse_id
            : null;

        if ($caisseId === null && $agencyId !== null) {
            $caisseId = DB::table('agencies')->where('id', $agencyId)->value('caisse_id');
            $caisseId = $caisseId !== null ? (int) $caisseId : null;
        }

        return [
            'type' => $type,
            'agency_id' => $agencyId,
            'caisse_id' => $caisseId,
            'user_id' => (int) $user->id,
        ];
    }

    public static function restrictedAgencyId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return 0;
        }

        $scope = self::scopeFor($user);

        return in_array($scope['type'], [self::AGENCY, self::PORTFOLIO], true)
            ? ($scope['agency_id'] ?? 0)
            : null;
    }

    public static function constrain(
        Builder $query,
        Request $request,
        string|Expression $agencyColumn,
        string|Expression|null $accountManagerColumn = null
    ): Builder {
        $user = $request->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $scope = self::scopeFor($user);
        if ($scope['type'] === self::PLATFORM) {
            return $query;
        }

        if ($scope['type'] === self::CAISSE) {
            if (! $scope['caisse_id']) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($agencyColumn, DB::table('agencies')
                ->select('id')
                ->where('caisse_id', $scope['caisse_id']));
        }

        $query->where($agencyColumn, $scope['agency_id'] ?? 0);
        if ($scope['type'] === self::PORTFOLIO) {
            if ($accountManagerColumn === null) {
                return $query->whereRaw('1 = 0');
            }
            $query->where($accountManagerColumn, $scope['user_id']);
        }

        return $query;
    }

    public static function canAccessAgency(Request $request, ?int $agencyId): bool
    {
        if ($agencyId === null || ! $request->user()) {
            return false;
        }

        $scope = self::scopeFor($request->user());

        return match ($scope['type']) {
            self::PLATFORM => true,
            self::CAISSE => DB::table('agencies')
                ->where('id', $agencyId)
                ->where('caisse_id', $scope['caisse_id'] ?? 0)
                ->exists(),
            default => $agencyId === ($scope['agency_id'] ?? null),
        };
    }

    public static function canAccessClient(Request $request, int $clientId): bool
    {
        $query = DB::table('clients as c')
            ->leftJoin('accounts as a', 'a.client_id', '=', 'c.id')
            ->where('c.id', $clientId);
        self::constrain($query, $request, 'c.agency_id', 'a.account_manager_id');

        return $query->exists();
    }

    public static function canAccessAccount(Request $request, int $accountId): bool
    {
        $query = DB::table('accounts as a')
            ->join('clients as c', 'c.id', '=', 'a.client_id')
            ->where('a.id', $accountId);
        self::constrain($query, $request, 'c.agency_id', 'a.account_manager_id');

        return $query->exists();
    }

    public static function canAccessTransaction(Request $request, int $transactionId): bool
    {
        $query = DB::table('transactions as t')
            ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
            ->where('t.id', $transactionId);
        self::constrain($query, $request, 't.agency_id', 'a.account_manager_id');

        return $query->exists();
    }

    public static function canAccessAlert(Request $request, int $alertId): bool
    {
        $query = DB::table('alerts as al')
            ->leftJoin('clients as c', 'c.id', '=', 'al.client_id')
            ->leftJoin('transactions as t', 't.id', '=', 'al.transaction_id')
            ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
            ->where('al.id', $alertId);
        self::constrain(
            $query,
            $request,
            DB::raw('COALESCE(t.agency_id, c.agency_id)'),
            'a.account_manager_id'
        );

        return $query->exists();
    }
}
