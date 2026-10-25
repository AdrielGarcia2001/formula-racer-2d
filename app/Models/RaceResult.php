<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RaceResult extends Model
{
    use HasFactory;

    protected $table = 'race_results';

    protected $fillable = [
        'room_id',
        'player_id',
        'position',
        'points',
        'laps_completed',
        'best_lap_ms',
        'total_time_ms',
        'fastest_lap',
        'classified',
    ];

    protected $casts = [
        'position' => 'integer',
        'points' => 'integer',
        'laps_completed' => 'integer',
        'best_lap_ms' => 'integer',
        'total_time_ms' => 'integer',
        'fastest_lap' => 'boolean',
        'classified' => 'boolean',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
