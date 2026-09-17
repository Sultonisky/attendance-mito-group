<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_face_profile_id',
    'model_version',
    'embedding_reference',
    'provider',
    'status',
    'idempotency_key',
])]
class EmployeeFaceEmbedding extends Model
{
    /**
     * The owning face profile.
     */
    public function faceProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeFaceProfile::class);
    }
}
