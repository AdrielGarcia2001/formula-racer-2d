<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('is_connected');
            $table->unsignedInteger('finish_position')->nullable()->after('finished_at');
            $table->unsignedBigInteger('total_time_ms')->nullable()->after('finish_position');
            $table->unsignedInteger('best_lap_ms')->nullable()->after('total_time_ms');
            $table->boolean('fastest_lap')->default(false)->after('best_lap_ms');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn(['finished_at', 'finish_position', 'total_time_ms', 'best_lap_ms', 'fastest_lap']);
        });
    }
};
