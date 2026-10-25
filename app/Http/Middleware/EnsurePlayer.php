<?php

namespace App\Http\Middleware;

use App\Models\Player;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identidad anonima: en la primera visita crea un jugador con un nombre
 * libre y lo autentica en el guard 'player'. Sin contrasena, sin registro.
 *
 * Los canales privados de Reverb exigen un usuario autenticado, asi que sin
 * esto ninguna suscripcion privada funciona (403).
 */
class EnsurePlayer
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('player');

        if (! $guard->check()) {
            $this->registerGuest($guard);
        } else {
            // No escribir en la base en cada peticion: solo si paso 1 minuto.
            $player = $guard->user();

            if ($player->last_seen_at === null || $player->last_seen_at->lt(now()->subMinute())) {
                $player->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }

    private function registerGuest($guard): void
    {
        $player = Player::create([
            ...$this->pickDisplayName(),
            'livery' => null,
            'token_hash' => hash('sha256', Str::random(64)),
        ]);

        $guard->login($player);
    }

    /**
     * @return array{display_name: string, display_name_norm: string}
     */
    private function pickDisplayName(): array
    {
        $prefixes = ['Piloto', 'Corredor', 'Driver', 'Racer', 'Jinete', 'Aviador'];

        for ($i = 0; $i < 30; $i++) {
            $candidate = $prefixes[array_rand($prefixes)].'-'.random_int(100, 999);
            $norm = mb_strtolower($candidate);

            if (! Player::where('display_name_norm', $norm)->exists()) {
                return ['display_name' => $candidate, 'display_name_norm' => $norm];
            }
        }

        $candidate = 'Piloto-'.Str::random(6);

        return ['display_name' => $candidate, 'display_name_norm' => mb_strtolower($candidate)];
    }
}