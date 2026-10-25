<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['room_id', 'player_id', 'body'];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
