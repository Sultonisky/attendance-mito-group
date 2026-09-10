<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'leave_balance_id',
    'leave_request_id',
    'transaction_type',
    'amount',
    'balance_after',
    'reason',
    'occurred_at',
    'metadata',
])]
#[WithoutTimestamps]
class LeaveTransaction extends Model
{
    /**
     * The owning employee.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The balance affected.
     */
    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }

    /**
     * The originating leave request, when applicable.
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
