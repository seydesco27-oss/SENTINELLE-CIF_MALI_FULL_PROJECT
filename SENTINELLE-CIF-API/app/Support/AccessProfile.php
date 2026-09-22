<?php

namespace App\Support;

use App\Models\User;

final class AccessProfile
{
    public const ADMIN = 1;

    public const COMPLIANCE_OFFICER = 2;

    public const SUPERVISOR = 3;

    public const AGENT = 4;

    private const ALL_PERMISSIONS = [
        'dashboard',
        'network',
        'alerts',
        'investigations',
        'centif',
        'clients',
        'accounts',
        'transactions',
        'screening',
        'risk_analysis',
        'ml',
        'reports',
        'audit',
        'settings',
        'assist',
    ];

    private const PERMISSIONS_BY_ROLE = [
        self::ADMIN => self::ALL_PERMISSIONS,
        self::COMPLIANCE_OFFICER => self::ALL_PERMISSIONS,
        self::SUPERVISOR => [
            'dashboard',
            'network',
            'alerts',
            'investigations',
            'centif',
            'clients',
            'accounts',
            'transactions',
            'screening',
            'risk_analysis',
            'reports',
            'settings',
            'assist',
        ],
        self::AGENT => [
            'clients',
            'accounts',
            'transactions',
            'settings',
        ],
    ];

    public static function permissions(?int $roleId): array
    {
        return self::PERMISSIONS_BY_ROLE[$roleId] ?? ['settings'];
    }

    public static function defaultPath(?int $roleId): string
    {
        return in_array('dashboard', self::permissions($roleId), true)
            ? '/dashboard'
            : '/clients';
    }

    public static function workspaceLabel(?int $roleId): string
    {
        return match ($roleId) {
            self::ADMIN => 'Administration et supervision globale',
            self::COMPLIANCE_OFFICER => 'Conformité et analyse LBC-FT',
            self::SUPERVISOR => 'Supervision opérationnelle',
            self::AGENT => 'Consultation opérationnelle',
            default => 'Espace utilisateur',
        };
    }

    public static function forUser(User $user): array
    {
        $roleId = $user->role_id !== null ? (int) $user->role_id : null;

        return [
            'workspace_label' => self::workspaceLabel($roleId),
            'default_path' => self::defaultPath($roleId),
            'permissions' => self::permissions($roleId),
        ];
    }
}
