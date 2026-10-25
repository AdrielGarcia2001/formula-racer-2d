<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Player extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'players';

    protected $fillable = [
        'display_name',
        'display_name_norm',
        'livery',
        'token_hash',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'livery' => 'array',
        'is_banned' => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}