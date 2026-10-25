<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['host_player_id', 'name', 'type', 'weather_seed', 'lap_count', 'status', 'track_id'];

    public function host() {
        return $this->belongsTo(Player::class, 'host_player_id');
    }

    public function members() {
        return $this->hasMany(RoomMember::class);
    }

    public function track() {
        return $this->belongsTo(Track::class);
    }}
