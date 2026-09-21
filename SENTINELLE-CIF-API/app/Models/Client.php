<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'clients';
    public $timestamps = false;

    protected $fillable = [
        'client_number',
        'client_type',
        'status',
        'phone',
        'email',
        'is_pep',
        'risk_level_id',
        'risk_score',
        'agency_id',
    ];

    protected $casts = [
        'is_pep' => 'boolean',
        'risk_score' => 'decimal:2',
        'agency_id' => 'integer',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'id');
    }

    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'risk_level_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'client_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'client_id');
    }
}
