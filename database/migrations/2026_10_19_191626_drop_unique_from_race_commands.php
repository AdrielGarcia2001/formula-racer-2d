<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_commands', function (Blueprint $table) {
            $table->dropUnique('race_commands_room_id_player_id_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::table('race_commands', function (Blueprint $table) {
            $table->unique(['room_id', 'player_id', 'seq'], 'race_commands_room_id_player_id_seq_unique');
        });
    }
};