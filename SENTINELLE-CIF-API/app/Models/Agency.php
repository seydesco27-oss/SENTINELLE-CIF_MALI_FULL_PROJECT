<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $table = 'agencies';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'city',
        'caisse_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'caisse_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_id', 'id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'agency_id', 'id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'agency_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'agency_id', 'id');
    }
}
