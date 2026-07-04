<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_port',
        'internal_ip',
        'internal_port',
        'protocol',
        'description',
        'status',
    ];

    protected $casts = [
        'public_port' => 'integer',
        'internal_port' => 'integer',
    ];
}
