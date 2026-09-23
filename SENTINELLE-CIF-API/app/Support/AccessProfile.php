<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AccessProfile
{
    public const ADMIN = 1;
    public const COMPLIANCE_OFFICER = 2;
    public const SUPERVISOR = 3;
    public const AGENT = 4;
    public const ACCOUNT_MANAGER = 5;

    public const ALL_PERMISSION_CODES = [
        'nav.dashboard', 'nav.network', 'nav.alerts', 'nav.investigations',
        'nav.centif', 'nav.clients', 'nav.accounts', 'nav.transactions',
        'nav.screening', 'nav.analyse', 'nav.ml', 'nav.reports', 'nav.audit',
        'nav.users', 'nav.engines', 'nav.onboarding_org', 'nav.settings',
        'data.scope_platform', 'data.scope_caisse', 'data.scope_agency',
        'data.scope_portfolio',
        'alert.view', 'alert.decide', 'alert.escalate', 'alert.signal',
        'investigation.view', 'investigation.manage',
        'centif.view', 'centif.manage',
        'client.view', 'client.create', 'client.status',
        'account.view', 'account.create', 'tx.view', 'tx.create',
        'screening.view', 'ml.use', 'report.view', 'audit.view',
        'user.manage', 'org.register', 'engine.configure', 'demo.run',
        'list.import', 'list.publish', 'screening.run_batch',
    ];

    private const PERMISSIONS_BY_ROLE = [
        self::ADMIN => self::ALL_PERMISSION_CODES,
        self::COMPLIANCE_OFFICER => [
            'nav.dashboard', 'nav.network', 'nav.alerts', 'nav.investigations',
            'nav.centif', 'nav.clients', 'nav.accounts', 'nav.transactions',
            'nav.screening', 'nav.analyse', 'nav.ml', 'nav.reports',
            'nav.audit', 'nav.settings', 'data.scope_caisse',
            'alert.view', 'alert.decide', 'alert.escalate', 'alert.signal',
            'investigation.view', 'investigation.manage',
            'centif.view', 'centif.manage', 'client.view', 'client.create',
            'client.status', 'account.view', 'account.create', 'tx.view',
            'tx.create', 'screening.view', 'ml.use', 'report.view', 'audit.view',
        ],
        self::SUPERVISOR => [
            'nav.dashboard', 'nav.network', 'nav.alerts', 'nav.investigations',
            'nav.clients', 'nav.accounts', 'nav.transactions', 'nav.screening',
            'nav.reports', 'nav.settings', 'data.scope_agency',
            'alert.view', 'alert.escalate', 'alert.signal',
            'investigation.view', 'investigation.manage', 'client.view',
            'client.create', 'account.view', 'account.create', 'tx.view',
            'tx.create', 'screening.view', 'report.view',
        ],
        self::AGENT => [
            'nav.dashboard', 'nav.alerts', 'nav.clients', 'nav.accounts',
            'nav.transactions', 'nav.settings', 'data.scope_agency',
            'alert.view', 'alert.signal', 'client.view', 'account.view',
            'tx.view', 'tx.create',
        ],
        self::ACCOUNT_MANAGER => [
            'nav.dashboard', 'nav.alerts', 'nav.clients', 'nav.accounts',
            'nav.transactions', 'nav.settings', 'data.scope_portfolio',
            'alert.view', 'alert.signal', 'client.view', 'account.view',
            'tx.view', 'tx.create',
        ],
    ];

    public static function permissions(?int $roleId): array
    {
        return self::PERMISSIONS_BY_ROLE[$roleId] ?? ['nav.settings'];
    }

    public static function permissionsForUser(User $user): array
    {
        $rolePermissions = self::permissions((int) $user->role_id);
        try {
            if (Schema::hasTable('role_permissions')) {
                $storedPermissions = DB::table('role_permissions')
                    ->where('role_id', (int) $user->role_id)
                    ->get(['permission_code', 'allowed']);
                if ($storedPermissions->isNotEmpty()) {
                    $rolePermissions = $storedPermissions
                        ->where('allowed', true)
                        ->pluck('permission_code')
                        ->map(fn ($permission): string => (string) $permission)
                        ->all();
                }
            }
        } catch (\Throwable) {
            // Le profil embarqué reste disponible pendant l'installation/migration.
        }

        $permissions = array_values(array_filter(
            $rolePermissions,
            fn (string $permission): bool => ! str_starts_with($permission, 'data.scope_')
        ));
        $adminOnly = ['org.register', 'user.manage', 'nav.users', 'nav.onboarding_org', 'list.import', 'list.publish', 'screening.run_batch'];
        $businessWrites = ['alert.decide', 'alert.escalate', 'alert.signal', 'investigation.manage', 'centif.manage', 'client.create', 'client.status', 'account.create', 'tx.create'];
        $permissions = array_values(array_filter($permissions, fn ($code) => (int) $user->role_id === self::ADMIN
            ? (!str_starts_with($code, 'nav.') || in_array($code, ['nav.users', 'nav.onboarding_org', 'nav.audit', 'nav.settings', 'nav.engines'], true)) && !in_array($code, $businessWrites, true)
            : !in_array($code, $adminOnly, true)));
        $permissions[] = 'data.scope_'.strtolower(AgencyAccess::scopeFor($user)['type']);

        return array_values(array_unique($permissions));
    }

    public static function allows(User $user, string $permission): bool
    {
        return in_array($permission, self::permissionsForUser($user), true);
    }

    public static function defaultPath(?int $roleId): string
    {
        if ($roleId === self::ADMIN) return '/admin/structures';
        return in_array('nav.dashboard', self::permissions($roleId), true)
            ? '/dashboard'
            : '/parametres';
    }

    public static function workspaceLabel(?int $roleId): string
    {
        return match ($roleId) {
            self::ADMIN => 'Administration et gouvernance de la plateforme',
            self::COMPLIANCE_OFFICER => 'Conformité et décisions LBC-FT',
            self::SUPERVISOR => 'Supervision opérationnelle locale',
            self::AGENT => 'Opérations de l’agence',
            self::ACCOUNT_MANAGER => 'Portefeuille clients',
            default => 'Espace utilisateur',
        };
    }

    public static function forUser(User $user): array
    {
        $roleId = $user->role_id !== null ? (int) $user->role_id : null;

        return [
            'workspace_label' => $roleId === self::SUPERVISOR && AgencyAccess::scopeFor($user)['type'] === AgencyAccess::CAISSE ? 'Administration de caisse' : self::workspaceLabel($roleId),
            'default_path' => self::defaultPath($roleId),
            'permissions' => self::permissionsForUser($user),
            'scope' => AgencyAccess::scopeFor($user),
        ];
    }
}
