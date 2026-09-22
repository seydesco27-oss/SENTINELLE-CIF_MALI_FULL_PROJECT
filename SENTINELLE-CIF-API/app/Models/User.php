<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * Table métier DigiAML.
     */
    protected $table = 'users';

    /**
     * Clé primaire.
     */
    protected $primaryKey = 'id';

    /**
     * DigiAML utilise son propre schéma de timestamps.
     *
     * Il n'existe pas de updated_at dans users.
     */
    public $timestamps = false;

    /**
     * Champs pouvant être remplis.
     */
    protected $fillable = [
        'agency_id',
        'role_id',
        'username',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'profile_updated_at',
        'password_hash',
    ];

    /**
     * Champs masqués lors de la sérialisation JSON.
     *
     * IMPORTANT :
     * le hash du mot de passe ne doit jamais apparaître
     * dans les réponses API.
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Casting.
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'agency_id' => 'integer',
            'role_id' => 'integer',
            'created_at' => 'datetime',
            'profile_updated_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        $fullName = trim(implode(' ', array_filter([
            $this->first_name,
            $this->last_name,
        ])));

        return $fullName !== '' ? $fullName : (string) $this->username;
    }

    /**
     * Laravel/Sanctum doit savoir où se trouve
     * le mot de passe réellement stocké.
     *
     * Laravel utilise normalement "password".
     * DigiAML utilise "password_hash".
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Agence de rattachement.
     *
     * users.agency_id -> agencies.id
     */
    public function agency()
    {
        return $this->belongsTo(
            Agency::class,
            'agency_id',
            'id'
        );
    }

    /**
     * Rôle de l'utilisateur.
     *
     * users.role_id -> roles.id
     */
    public function role()
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
            'id'
        );
    }
}
