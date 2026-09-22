<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleExecution extends Model
{
    protected $table = 'rule_executions';

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'transaction_id',
        'execution_result',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AmlRule::class, 'rule_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}