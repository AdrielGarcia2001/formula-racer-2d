<?php

namespace App\Services;

class WeatherService
{
    /**
     * Tipos de clima. `risk` es la probabilidad de incidente por sector de pista
     * cuando el piloto va en modo agresivo.
     */
    public const TYPES = [
        'clear'  => ['name' => 'Seco',    'wetness' => 0.00, 'visibility' => 1.00, 'risk' => 0.000],
        'cloudy' => ['name' => 'Nublado', 'wetness' => 0.10, 'visibility' => 0.90, 'risk' => 0.002],
        'wet'    => ['name' => 'Mojado',  'wetness' => 0.45, 'visibility' => 0.75, 'risk' => 0.010],
        'rain'   => ['name' => 'Lluvia',  'wetness' => 0.65, 'visibility' => 0.60, 'risk' => 0.020],
        'fog'    => ['name' => 'Niebla',  'wetness' => 0.15, 'visibility' => 0.35, 'risk' => 0.004],
    ];

    public const EMOJI = [
        'clear'  => '☀️',
        'cloudy' => '☁️',
        'wet'    => '💧',
        'rain'   => '🌧️',
        'fog'    => '🌫️',
    ];

    /**
     * Deriva el clima de forma determinista: la misma semilla da siempre
     * el mismo clima y la misma variabilidad interna.
     *
     * seed % 5                -> tipo de clima
     * intdiv(seed, 5) % 100   -> variabilidad interna (intensidad, charcos)
     */
    public static function fromSeed(int $seed): array
    {
        $keys = array_keys(self::TYPES);
        $count = count($keys);
        $index = abs($seed) % $count;
        $key = $keys[$index];
        $base = self::TYPES[$key];

        $variation = intdiv(abs($seed), $count) % 100;

        return [
            'key' => $key,
            'name' => $base['name'],
            'emoji' => self::EMOJI[$key],
            'wetness' => $base['wetness'],
            'visibility' => $base['visibility'],
            'risk' => $base['risk'],
            'variation' => $variation,
            'intensity' => $variation / 100,
            'track_wetness' => $base['wetness'],
            'is_wet' => in_array($key, ['wet', 'rain'], true),
            'label' => self::EMOJI[$key] . ' ' . $base['name'],
        ];
    }

    /**
     * Construye la semilla que produce un clima concreto. Es el inverso de
     * fromSeed() y lo usa el lobby cuando el host elige el clima.
     */
    public static function seedFor(string $key, int $variation = 0): int
    {
        $keys = array_keys(self::TYPES);
        $count = count($keys);
        $index = array_search($key, $keys, true);

        if ($index === false) {
            $index = 0;
        }

        return $index + (($variation % 100) * $count);
    }

    public static function all(): array
    {
        return self::TYPES;
    }
}
