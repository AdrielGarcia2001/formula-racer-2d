<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->constrained('players');
            $table->unsignedInteger('position');
            $table->unsignedTinyInteger('points');
            $table->unsignedSmallInteger('laps_completed')->default(0);
            $table->unsignedInteger('best_lap_ms')->nullable();
            $table->unsignedBigInteger('total_time_ms')->nullable();
            $table->boolean('fastest_lap')->default(false);
            $table->boolean('classified')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'player_id']);
            $table->index(['room_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
    }
};
