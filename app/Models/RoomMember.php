<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomMember extends Model
{
    protected $fillable = [
        'room_id', 'player_id', 'role', 'is_ready', 'is_connected',
        'finished_at', 'finish_position', 'total_time_ms', 'best_lap_ms', 'fastest_lap',
    ];

    protected $casts = [
        'is_ready' => 'boolean',
        'is_connected' => 'boolean',
        'finished_at' => 'datetime',
        'finish_position' => 'integer',
        'total_time_ms' => 'integer',
        'best_lap_ms' => 'integer',
        'fastest_lap' => 'boolean',
    ];

    public function room() {
        return $this->belongsTo(Room::class);
    }

    public function player() {
        return $this->belongsTo(Player::class);
    }}
