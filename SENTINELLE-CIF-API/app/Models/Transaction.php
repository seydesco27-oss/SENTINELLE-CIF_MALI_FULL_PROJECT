<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $table = 'transactions';
    public $timestamps = false;

    protected $fillable = [
        'transaction_reference',
        'account_id',
        'agency_id',
        'transaction_type',
        'amount',
        'currency',
        'channel',
        'country_from',
        'country_to',
        'country',
        'transaction_date',
        'transaction_status',
        'status_changed_at',
        'cancellation_reason',
        'reversal_of_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_of_transaction_id');
    }

    public function ruleExecutions(): HasMany
    {
        return $this->hasMany(RuleExecution::class, 'transaction_id');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class, 'transaction_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'transaction_id');
    }
}
