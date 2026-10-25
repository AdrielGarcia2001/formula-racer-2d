<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('display_name', 30);
            // Unico e insensible a mayusculas: impide que dos usuarios se llamen igual.
            $table->string('display_name_norm', 30)->unique();
            $table->json('livery')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_banned')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};