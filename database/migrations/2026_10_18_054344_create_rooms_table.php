<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_player_id')->constrained('players');
            $table->string('name');
            $table->enum('type', ['practice', 'sprint', 'league']);
            $table->integer('weather_seed');
            $table->tinyInteger('lap_count');
            $table->tinyInteger('max_players')->default(12);
            $table->enum('status', ['waiting', 'racing', 'finished', 'abandoned'])->default('waiting');
            $table->string('invite_code', 12)->unique()->nullable();
            $table->timestamp('sim_last_beat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};