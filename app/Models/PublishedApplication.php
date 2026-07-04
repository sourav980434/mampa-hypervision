<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublishedApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'vm_uuid',
        'name',
        'public_url',
        'internal_port',
        'protocol',
        'status',
    ];

    protected $casts = [
        'internal_port' => 'integer',
    ];
}
