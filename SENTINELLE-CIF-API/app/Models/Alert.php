<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $table = 'alerts';

    public $timestamps = false;

    protected $fillable = [
        'reference',
        'client_id',
        'transaction_id',
        'alert_type',
        'priority',
        'status',
        'final_score',
        'title',
        'description',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
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