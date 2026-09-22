<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caisse extends Model
{
    protected $table = 'caisses';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'country',
        'city',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class, 'caisse_id');
    }
}
