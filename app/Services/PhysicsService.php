<?php

namespace App\Services;

class PhysicsService
{
    /**
     * Compresion temporal: la carrera no va a tiempo real.
     * Con 6.0 una vuelta de Monza dura unos 30-35 segundos.
     */
    private const TIME_SCALE = 6.0;

    private const MAX_BOOST_CHARGES = 3;

    /**
     * Un paso de simulacion. Recibe el estado del auto y devuelve el nuevo.
     *
     * El auto avanza sobre el circuito: lleva un recorrido (`distance`) en
     * unidades de mundo y el servicio de pista devuelve su posicion y el
     * angulo de la tangente.
     *
     * El clima se aplica via `$weather`: pista mojada baja el tope de velocidad
     * y un auto que esta patinando (`grip_loss_ticks_left`) pierde agarre.
     */
    public static function tickCar(array $car, array $track, array $weather): array
    {
        $multipliers = [
            'conserve'   => 82.0,
            'normal'     => 100.0,
            'aggressive' => 118.0,
        ];

        $mode = $car['mode'] ?? 'normal';
        $target = $multipliers[$mode] ?? 100.0;

        // ---------- Combustible ----------
        $fuel = (float) ($car['fuel'] ?? 100.0);
        $fuelBurn = match ($mode) { 'conserve' => 0.04, 'aggressive' => 0.09, default => 0.06 };
        if ($car['boost_active'] ?? false) {
            $fuelBurn += 0.06;
        }
        $fuel = max(0.0, $fuel - $fuelBurn);

        if ($fuel <= 0.0) {
            $target *= 0.35;   // se quedo sin nafta: va a paso de hombre
        } elseif ($fuel < 15.0) {
            $target *= 0.85;   // ultimos litros: pierde potencia
        }

        // ---------- Temperatura de llantas ----------
        // El modo agresivo calienta, el conservador las cuida; el boost las castiga.
        $tireTemp = (float) ($car['tire_temp'] ?? 45.0);
        $heating = match ($mode) { 'conserve' => 0.05, 'aggressive' => 1.0, default => 0.35 };
        if ($car['boost_active'] ?? false) {
            $heating += 0.6;
        }
        $tireTemp = min(100.0, max(25.0, $tireTemp + $heating - (($tireTemp - 45.0) * 0.03)));

        if ($tireTemp > 90.0) {
            $target *= 0.85;   // llantas quemadas: agarre caido
        } elseif ($tireTemp > 78.0) {
            $target *= 0.94;
        }

        $wetness = $weather['track_wetness'] ?? 0.0;
        $target *= (1.0 - ($wetness * 0.25));

        $gripLoss = (int) ($car['grip_loss_ticks_left'] ?? 0);
        $sliding = $gripLoss > 0;

        // Patinando: el auto no agarra y pierde bastante velocidad
        if ($sliding) {
            $target *= 0.70;
        }

        $current = $car['speed'] ?? $target;

        if ($car['boost_active'] ?? false) {
            $current = $target + 120.0;
        } else {
            $current += ($target - $current) * 0.25;
        }

        $retired = (bool) ($car['retired'] ?? false);

        if ($retired) {
            // Se salio de la pista: el auto queda tirado en laastian
            $current = 0.0;
        }

        $metersPerTick = ($current / 3.6) * 0.1 * self::TIME_SCALE;
        $worldUnitsPerTick = $retired ? 0.0 : $metersPerTick * ($track['worldPerMeter'] ?? 1.0);

        $distance = ($car['distance'] ?? 0.0) + $worldUnitsPerTick;
        $laps = (int) ($car['laps'] ?? 0);
        $length = (float) ($track['length'] ?? 0.0);

        $completedLap = false;
        while ($length > 0 && $distance >= $length) {
            $distance -= $length;
            $laps++;
            $completedLap = true;
        }

        $boostCharges = (int) ($car['boost_charges'] ?? self::MAX_BOOST_CHARGES);

        if ($completedLap) {
            $boostCharges = min(self::MAX_BOOST_CHARGES, $boostCharges + 1);
        }

        $point = TrackService::pointAt($track, $distance);

        // El slide es el desplazamiento extra por patinazo, que se suma al carril
        $offset = (float) ($car['lane_offset'] ?? 0.0) + (float) ($car['slide'] ?? 0.0);
        $rad = deg2rad($point['angle']);
        $normalX = -sin($rad);
        $normalY = cos($rad);

        return [
            'x' => round($point['x'] + ($normalX * $offset), 2),
            'y' => round($point['y'] + ($normalY * $offset), 2),
            'angle' => round($point['angle'], 2),
            'speed' => round($current, 1),
            'gear' => (int) floor($current / 30),
            'mode' => $mode,
            'distance' => round($distance, 2),
            'laps' => $laps,
            'lane_offset' => (float) ($car['lane_offset'] ?? 0.0),
            'slide' => round((float) ($car['slide'] ?? 0.0), 2),
            'sliding' => $sliding,
            'grip_loss_ticks_left' => $gripLoss,
            'retired' => $retired,
            'boost_active' => $car['boost_active'] ?? false,
            'boost_charges' => $boostCharges,
            'boost_ticks_left' => $car['boost_ticks_left'] ?? 0,
            'fuel' => round($fuel, 1),
            'tire_temp' => round($tireTemp, 1),
        ];
    }

    /**
     * Estado inicial de un auto: arranca en la linea de largada.
     */
    public static function initialCar(int $playerId, array $track, float $laneOffset = 0.0, float $distance = 0.0): array
    {
        $point = TrackService::pointAt($track, $distance);
        $rad = deg2rad($point['angle']);

        return [
            'id' => $playerId,
            'x' => round($point['x'] - (sin($rad) * $laneOffset), 2),
            'y' => round($point['y'] + (cos($rad) * $laneOffset), 2),
            'angle' => round($point['angle'], 2),
            'speed' => 100.0,
            'gear' => 3,
            'mode' => 'normal',
            'distance' => round($distance, 2),
            'laps' => 0,
            'lane_offset' => $laneOffset,
            'slide' => 0.0,
            'sliding' => false,
            'grip_loss_ticks_left' => 0,
            'retired' => false,
            'boost_active' => false,
            'boost_charges' => self::MAX_BOOST_CHARGES,
            'boost_ticks_left' => 0,
            'fuel' => 100.0,
            'tire_temp' => 45.0,
        ];
    }

    /**
     * Reparte a los autos por carriles para que no se superpongan.
     */
    public static function laneOffsetFor(int $index, float $trackWidth): float
    {
        $max = $trackWidth / 2 - 8;
        $step = 26.0;

        $slot = intdiv($index + 1, 2);
        $offset = $index % 2 === 0 ? -$slot * $step : $slot * $step;

        if ($offset > $max) {
            $offset = $max;
        }

        if ($offset < -$max) {
            $offset = -$max;
        }

        return $offset;
    }
}
