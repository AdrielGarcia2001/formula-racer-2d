<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\Room;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Envia un mensaje al chat de la sala: se guarda y se difunde por Reverb
     * en el mismo canal publico que la carrera (race.{roomId}).
     */
    public function store(Request $request, Room $room)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:200',
        ]);

        $player = auth('player')->user();

        $message = ChatMessage::create([
            'room_id' => $room->id,
            'player_id' => $player->id,
            'body' => $validated['body'],
        ]);

        broadcast(new ChatMessageSent($room->id, [
            'id' => $message->id,
            'player_id' => $player->id,
            'name' => $player->display_name,
            'body' => $message->body,
            'at' => now()->format('H:i'),
        ]));

        return response()->json(['ok' => true]);
    }
}
