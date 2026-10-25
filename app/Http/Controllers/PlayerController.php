<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function updateName(Request $request)
    {
        $validated = $request->validate([
            'display_name' => 'required|string|min:2|max:20',
        ]);

        $player = auth('player')->user();
        $norm = mb_strtolower($validated['display_name']);

        // Unicidad: si otro jugador ya usa ese nombre, rechazar
        $exists = \App\Models\Player::where('display_name_norm', $norm)
            ->where('id', '!=', $player->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['display_name' => 'Ese nombre ya está en uso']);
        }

        $player->forceFill([
            'display_name' => $validated['display_name'],
            'display_name_norm' => $norm,
        ])->save();

        return back()->with('status', 'Nombre actualizado');
    }

    public function updateLivery(Request $request)
    {
        $validated = $request->validate([
            'primary' => 'required|string|max:7',
            'secondary' => 'required|string|max:7',
        ]);

        $player = auth('player')->user();
        $player->forceFill(['livery' => $validated])->save();

        return back()->with('status', 'Livrea actualizada');
    }
}
