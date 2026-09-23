<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'assignment_id',
    'pin_id',
])]
class OutsourceAssignmentPin extends Model
{
    /**
     * @return BelongsTo<OutsourceStoreAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OutsourceStoreAssignment::class, 'assignment_id');
    }

    /**
     * @return BelongsTo<WorkLocationPin, $this>
     */
    public function pin(): BelongsTo
    {
        return $this->belongsTo(WorkLocationPin::class, 'pin_id');
    }
}
