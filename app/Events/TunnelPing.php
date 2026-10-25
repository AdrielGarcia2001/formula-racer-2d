<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TunnelPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $sentAt) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tunnel-check')];
    }

    public function broadcastAs(): string
    {
        return 'tunnel.ping';
    }

    public function broadcastWith(): array
    {
        return [
            'sent_at' => $this->sentAt,
            'server' => 'laravel-reverb',
        ];
    }
}