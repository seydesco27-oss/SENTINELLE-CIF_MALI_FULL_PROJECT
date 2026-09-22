<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmlRule extends Model
{
    protected $table = 'aml_rules';

    protected $fillable = [
        'rule_code',
        'name',
        'description',
        'severity',
        'score',
        'active',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'active' => 'boolean',
    ];
}