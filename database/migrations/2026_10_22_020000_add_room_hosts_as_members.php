<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las salas creadas antes de que existiera la fila del anfitrion en
     * room_members se quedan sin autos. Se agrega al host como 'host'.
     */
    public function up(): void
    {
        DB::table('rooms')->orderBy('id')->each(function ($room) {
            $exists = DB::table('room_members')
                ->where('room_id', $room->id)
                ->where('player_id', $room->host_player_id)
                ->exists();

            if ($exists) {
                return;
            }

            DB::table('room_members')->insert([
                'room_id' => $room->id,
                'player_id' => $room->host_player_id,
                'role' => 'host',
                'is_connected' => true,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('room_members')
            ->whereIn('player_id', DB::table('rooms')->pluck('host_player_id'))
            ->where('role', 'host')
            ->delete();
    }
};
