<?php

namespace App\Http\Controllers;

use App\Models\RaceResult;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function show(\App\Models\Room $room)
    {
        $this->ensureMember($room);

        $room->load('members.player');

        $roster = $room->members
            ->map(fn ($member) => [
                'id' => $member->player_id,
                'name' => $member->player?->display_name ?? ('Piloto-' . $member->player_id),
                'role' => $member->role,
                'livery' => $member->player?->livery ?? null,
            ])
            ->values();

        $chat = \App\Models\ChatMessage::where('room_id', $room->id)
            ->with('player')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->map(fn ($m) => [
                'player_id' => $m->player_id,
                'name' => $m->player?->display_name ?? ('Piloto-' . $m->player_id),
                'body' => $m->body,
                'at' => $m->created_at->format('H:i'),
            ])
            ->values();

        return view('rooms.race', ['room' => $room, 'roster' => $roster, 'chat' => $chat]);
    }

    /**
     * Si el jugador no es miembro todavia lo agrega. El anfitrion entra como
     * host, si la carrera ya termino entra como espectador y si la sala esta
     * llena tambien. Sin esto el simulador no le crea un auto.
     */
    private function ensureMember(Room $room): void
    {
        $playerId = auth('player')->id();
        $member = $room->members()->where('player_id', $playerId)->first();

        if ($member) {
            if ($room->host_player_id === $playerId) {
                if ($member->role !== 'host') {
                    $member->forceFill(['role' => 'host'])->saveQuietly();
                }

                return;
            }

            // Si estaba mirando y la carrera no termino y hay lugar, pasa a piloto
            if ($member->role === 'spectator' && $room->status !== 'finished' && $this->hasFreeSlot($room)) {
                $member->forceFill(['role' => 'player'])->saveQuietly();
            }

            return;
        }

        $room->members()->create([
            'player_id' => $playerId,
            'role' => $this->roleFor($room, $playerId),
            'is_connected' => true,
        ]);
    }

    /**
     * Que rol corresponde: el anfitrion siempre maneja auto, si la carrera ya
     * termino el que llega entra a mirar y si la sala esta llena tambien.
     */
    private function roleFor(Room $room, int $playerId): string
    {
        if ($room->host_player_id === $playerId) {
            return 'host';
        }

        if ($room->status === 'finished') {
            return 'spectator';
        }

        return $this->hasFreeSlot($room) ? 'player' : 'spectator';
    }

    private function hasFreeSlot(Room $room): bool
    {
        $drivers = $room->members()->whereIn('role', ['host', 'player'])->count();

        return $drivers < (int) $room->max_players;
    }

    /**
     * Estado actual de la carrera: sirve para que un piloto que entra tarde
     * vea la clasificacion final y el tablero de posiciones.
     */
    public function state(Room $room)
    {
        $results = RaceResult::where('room_id', $room->id)
            ->with('player')
            ->orderBy('position')
            ->get()
            ->map(fn (RaceResult $result) => [
                'player_id' => $result->player_id,
                'position' => $result->position,
                'points' => $result->points,
                'laps' => $result->laps_completed,
                'best_lap_ms' => $result->best_lap_ms,
                'total_time_ms' => $result->total_time_ms,
                'fastest_lap' => $result->fastest_lap,
                'classified' => $result->classified,
                'display_name' => $result->player?->display_name ?? ('Piloto-' . $result->player_id),
            ])
            ->all();

        $roster = $room->members()
            ->with('player')
            ->get()
            ->map(fn ($member) => [
                'id' => $member->player_id,
                'name' => $member->player?->display_name ?? ('Piloto-' . $member->player_id),
                'role' => $member->role,
                'livery' => $member->player?->livery ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => $room->status,
            'results' => $results,
            'roster' => $roster,
        ]);
    }

    public function join(\App\Models\Room $room)
    {
        $playerId = auth('player')->id();

        $room->members()->updateOrCreate(
            ['player_id' => $playerId],
            ['role' => $this->roleFor($room, $playerId), 'is_connected' => true]
        );

        return redirect()->route('rooms.show', $room);
    }}
