<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RaceResultsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public array $results,
        public string $status
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('race.' . $this->roomId)];
    }

    public function broadcastAs(): string
    {
        return 'race.results';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'status' => $this->status,
            'results' => $this->results,
        ];
    }
}
