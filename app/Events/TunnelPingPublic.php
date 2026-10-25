<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TunnelPingPublic implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $sentAt) {}

    public function broadcastOn(): array
    {
        return [new Channel('tunnel-check-public')];
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
            'channel_type' => 'public',
        ];
    }
}