<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

final class AgencyAccess
{
    public static function restrictedAgencyId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user || (int) $user->role_id !== AccessProfile::AGENT) {
            return null;
        }

        return $user->agency_id !== null ? (int) $user->agency_id : 0;
    }

    public static function constrain(Builder $query, Request $request, string $agencyColumn): Builder
    {
        $agencyId = self::restrictedAgencyId($request);
        if ($agencyId !== null) {
            $query->where($agencyColumn, $agencyId);
        }

        return $query;
    }
}
