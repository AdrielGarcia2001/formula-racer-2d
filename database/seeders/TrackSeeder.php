<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrackSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tracks')->updateOrInsert(
            ['slug' => 'monza'],
            [
                'name' => 'Autodromo Nazionale Monza',
                'lap_length_m' => 5793,
                'geometry' => json_encode([
                    'control_points' => [
                        ['x' => 150, 'y' => 150, 'w' => 140],
                        ['x' => 620, 'y' => 120, 'w' => 140],
                        ['x' => 950, 'y' => 130, 'w' => 140],
                        ['x' => 1130, 'y' => 250, 'w' => 140],
                        ['x' => 1150, 'y' => 400, 'w' => 140],
                        ['x' => 1030, 'y' => 480, 'w' => 140],
                        ['x' => 1120, 'y' => 570, 'w' => 140],
                        ['x' => 1150, 'y' => 680, 'w' => 140],
                        ['x' => 1010, 'y' => 790, 'w' => 140],
                        ['x' => 800, 'y' => 800, 'w' => 140],
                        ['x' => 660, 'y' => 700, 'w' => 140],
                        ['x' => 560, 'y' => 780, 'w' => 140],
                        ['x' => 330, 'y' => 700, 'w' => 140],
                        ['x' => 260, 'y' => 540, 'w' => 140],
                        ['x' => 120, 'y' => 380, 'w' => 140],
                    ],
                ]),
                'active' => true,
            ]
        );
    }
}
