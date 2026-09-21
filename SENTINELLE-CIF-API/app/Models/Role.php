<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /**
     * Table DigiAML.
     */
    protected $table = 'roles';

    /**
     * Clé primaire.
     */
    protected $primaryKey = 'id';

    /**
     * Pas de timestamps Laravel automatiques.
     *
     * La table roles ne possède ni created_at
     * ni updated_at.
     */
    public $timestamps = false;

    /**
     * Champs remplissables.
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Casts.
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }

    /**
     * Utilisateurs possédant ce rôle.
     *
     * roles.id -> users.role_id
     */
    public function users(): HasMany
    {
        return $this->hasMany(
            User::class,
            'role_id',
            'id'
        );
    }
}
