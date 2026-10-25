<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->constrained('players');
            $table->unsignedInteger('seq');
            $table->string('type'); // mode | boost
            $table->string('value')->nullable(); // aggressive|normal|conserve
            $table->boolean('applied')->default(false);
            $table->timestamp('created_at')->useCurrent();

            // Idempotencia: un mismo jugador no puede repetir el mismo seq
            $table->unique(['room_id', 'player_id', 'seq']);
            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_commands');
    }
};