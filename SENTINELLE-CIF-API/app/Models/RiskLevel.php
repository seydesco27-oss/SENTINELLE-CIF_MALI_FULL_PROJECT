<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskLevel extends Model
{
    protected $table = 'risk_levels';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'label',
        'description',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'risk_level_id');
    }
}