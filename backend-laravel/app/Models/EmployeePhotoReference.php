<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'hris_employee_id',
    'hris_nik',
    'photo_path',
])]
class EmployeePhotoReference extends Model
{
    protected $table = 'employee_photo_references';
}
