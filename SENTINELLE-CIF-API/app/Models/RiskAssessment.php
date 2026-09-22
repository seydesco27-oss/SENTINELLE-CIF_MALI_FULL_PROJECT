<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    protected $table = 'risk_assessments';

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'transaction_id',
        'score',
        'risk_level',
        'assessment_type',
        'explanation',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}