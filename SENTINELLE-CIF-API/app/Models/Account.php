<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $table = 'accounts';
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'account_number',
        'account_type',
        'opening_balance',
        'current_balance',
        'currency',
        'status',
        'opened_at',
        'account_manager_id',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'opened_at' => 'date',
        'account_manager_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }
}
