<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Track extends Model
{
    use HasFactory;

    protected $table = 'tracks';

    protected $fillable = [
        'slug',
        'name',
        'geometry',
        'lap_length_m',
        'active',
    ];

    protected $casts = [
        'geometry' => 'array',
        'active' => 'boolean',
    ];
}