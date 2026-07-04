<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VmMetadata extends Model
{
    use HasFactory;

    protected $table = 'vm_metadata';

    protected $fillable = [
        'vm_uuid',
        'tags',
        'notes',
    ];

    protected $casts = [
        'tags' => 'array',
    ];
}
