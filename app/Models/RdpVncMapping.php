<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RdpVncMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'vm_uuid',
        'type',
        'public_port',
        'internal_port',
        'status',
    ];

    protected $casts = [
        'public_port' => 'integer',
        'internal_port' => 'integer',
    ];
}
