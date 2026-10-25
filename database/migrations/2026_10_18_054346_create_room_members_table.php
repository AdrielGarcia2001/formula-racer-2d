<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->constrained('players');
            $table->enum('role', ['host', 'player', 'spectator']);
            $table->boolean('is_ready')->default(false);
            $table->boolean('is_connected')->default(true);
            $table->timestamp('joined_at')->useCurrent();
            $table->unique(['room_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
    }
};