<?php

namespace App\Http\Controllers;

use App\Models\RaceCommand;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CommandController extends Controller
{
    /**
     * Recibe un comando del jugador (cambio de modo, boost o reinicio) y lo
     * guarda en la base de datos. El simulador lo lee en su proximo tick.
     *
     * Rate limit: 1 comando por segundo por jugador (lo pone el middleware throttle).
     */
    public function store(Request $request, Room $room)
    {
        $player = Auth::guard('player')->user();

        $validator = Validator::make($request->all(), [
            'seq' => 'required|integer|min:1',
            'type' => 'required|string|in:mode,boost,restart,start',
            'value' => 'nullable|string|in:conserve,normal,aggressive',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Comando invalido'], 422);
        }

        if ($request->type === 'mode' && ! $request->filled('value')) {
            return response()->json(['error' => 'Falta el modo'], 422);
        }

        // Reiniciar o dar la salida es potestad del host
        if (in_array($request->type, ['restart', 'start'], true) && $room->host_player_id !== $player->id) {
            return response()->json(['error' => 'Solo el host puede hacer eso'], 403);
        }

        $command = RaceCommand::create([
            'room_id' => $room->id,
            'player_id' => $player->id,
            'seq' => $request->seq,
            'type' => $request->type,
            'value' => $request->value,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $command->id,
            'seq' => $command->seq,
        ]);
    }
}