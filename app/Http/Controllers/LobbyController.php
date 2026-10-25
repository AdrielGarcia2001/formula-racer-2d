<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\WeatherService;
use Illuminate\Http\Request;

class LobbyController extends Controller
{
    public function index()
    {
        return view('lobby', [
            'rooms' => \App\Models\Room::with(['host', 'track'])->whereIn('status', ['waiting', 'racing'])->get(),
            'tracks' => Track::all(),
            'weathers' => WeatherService::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'track_id' => 'required|exists:tracks,id',
            'lap_count' => 'required|integer|min:2|max:10',
            'type' => 'required|in:practice,sprint,league',
            'weather' => 'nullable|in:clear,cloudy,wet,rain,fog',
        ]);

        // El clima elegido se codifica dentro del weather_seed de la sala
        $weatherSeed = isset($validated['weather']) && $validated['weather'] !== ''
            ? WeatherService::seedFor($validated['weather'], random_int(0, 99))
            : random_int(1, 1000000);

        $room = \App\Models\Room::create([
            'host_player_id' => auth('player')->id(),
            'name' => $validated['name'],
            'track_id' => $validated['track_id'],
            'lap_count' => $validated['lap_count'],
            'type' => $validated['type'],
            'weather_seed' => $weatherSeed,
        ]);

        // El creador entra como anfitrion: sin esta fila no tendria auto
        $room->members()->create([
            'player_id' => auth('player')->id(),
            'role' => 'host',
            'is_connected' => true,
        ]);

        return redirect()->route('rooms.show', $room);
    }}
