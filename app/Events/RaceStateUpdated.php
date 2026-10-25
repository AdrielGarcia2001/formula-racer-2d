<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RaceStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public array $cars,
        public int $seq,
        public array $roster = [],
        public string $phase = 'racing',
        public int $countdown = 0
    ) {}

    public function broadcastOn(): array
    {
        // Usamos un canal publico para asegurar que los datos lleguen sin trabas de permisos
        return [new Channel('race.' . $this->roomId)];
    }

    public function broadcastAs(): string
    {
        return 'race.state';
    }

    public function broadcastWith(): array
    {
        return [
            's' => $this->seq,
            'c' => $this->cars,
            // El roster solo viaja cuando cambia
            'r' => $this->roster,
            // p = countdown | racing | finished, cd = ms que faltan para la salida
            'p' => $this->phase,
            'cd' => $this->countdown,
        ];
    }
}