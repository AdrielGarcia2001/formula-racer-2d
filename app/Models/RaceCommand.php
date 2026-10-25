<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceCommand extends Model
{
    protected $table = 'race_commands';

    // Los comandos son inmutables: solo created_at
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'player_id',
        'seq',
        'type',
        'value',
    ];
}
