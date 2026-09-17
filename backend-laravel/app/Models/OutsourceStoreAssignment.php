<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outsource_id',
    'store_id',
    'status',
])]
class OutsourceStoreAssignment extends Model
{
    /** @use HasFactory<OutsourceStoreAssignmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Outsource>
     */
    public function outsource(): BelongsTo
    {
        return $this->belongsTo(Outsource::class);
    }

    /**
     * @return BelongsTo<WorkLocation>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'store_id');
    }
}
