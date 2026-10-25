<?php

namespace App\Services;

class TrackService
{
    private const SAMPLES_PER_SEGMENT = 20;

    private static array $cache = [];

    /**
     * Convierte la geometria del circuito en una pista utilizable:
     * puntos muestreados sobre una spline, longitud total, tangentes y escala.
     */
    public static function build(array $geometry, float $lapLengthMeters): array
    {
        $key = md5(json_encode($geometry).'|'.$lapLengthMeters);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $control = self::controlPoints($geometry);
        $points = self::sampleSpline($control);
        $n = count($points);

        $cum = [];
        $headings = [];
        $length = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $cum[$i] = $length;
            $j = ($i + 1) % $n;
            $dx = $points[$j]['x'] - $points[$i]['x'];
            $dy = $points[$j]['y'] - $points[$i]['y'];
            $headings[$i] = self::normalizeAngle(rad2deg(atan2($dy, $dx)));
            $length += sqrt(($dx * $dx) + ($dy * $dy));
        }
        $cum[$n] = $length;

        $width = 0.0;
        foreach ($control as $point) {
            $width = max($width, (float) ($point['w'] ?? 0));
        }

        $track = [
            'points' => $points,
            'cum' => $cum,
            'headings' => $headings,
            'length' => $length,
            'width' => $width > 0 ? $width : 60.0,
            'worldPerMeter' => $lapLengthMeters > 0 ? $length / $lapLengthMeters : 1.0,
        ];

        self::$cache[$key] = $track;

        return $track;
    }

    /**
     * Posicion y angulo sobre la pista a una distancia dada (en unidades de mundo).
     */
    public static function pointAt(array $track, float $distance): array
    {
        $length = (float) $track['length'];
        if ($length <= 0) {
            return ['x' => 0.0, 'y' => 0.0, 'angle' => 0.0];
        }

        $d = fmod($distance, $length);
        if ($d < 0) {
            $d += $length;
        }

        $n = count($track['points']);
        $cum = $track['cum'];
        $i = self::findSegment($cum, $d, $n);
        $j = ($i + 1) % $n;

        $segLen = $cum[$i + 1] - $cum[$i];
        $t = $segLen > 0 ? ($d - $cum[$i]) / $segLen : 0.0;

        $p0 = $track['points'][$i];
        $p1 = $track['points'][$j];

        $x = $p0['x'] + (($p1['x'] - $p0['x']) * $t);
        $y = $p0['y'] + (($p1['y'] - $p0['y']) * $t);

        $a0 = $track['headings'][$i];
        $a1 = $track['headings'][$j];
        $diff = self::normalizeAngle($a1 - $a0);
        if ($diff > 180) {
            $diff -= 360;
        }

        return [
            'x' => $x,
            'y' => $y,
            'angle' => self::normalizeAngle($a0 + ($diff * $t)),
        ];
    }

    public static function normalizeAngle(float $deg): float
    {
        $deg = fmod($deg, 360.0);

        if ($deg < 0) {
            $deg += 360.0;
        }

        return $deg;
    }

    private static function controlPoints(array $geometry): array
    {
        $raw = $geometry['control_points'] ?? [];
        $control = [];

        foreach ($raw as $point) {
            $point = (array) $point;
            if (! isset($point['x'], $point['y'])) {
                continue;
            }

            $control[] = [
                'x' => (float) $point['x'],
                'y' => (float) $point['y'],
                'w' => (float) ($point['w'] ?? 0),
            ];
        }

        if (count($control) < 3) {
            $control = [
                ['x' => 100.0, 'y' => 100.0, 'w' => 60.0],
                ['x' => 500.0, 'y' => 100.0, 'w' => 60.0],
                ['x' => 500.0, 'y' => 400.0, 'w' => 60.0],
                ['x' => 100.0, 'y' => 400.0, 'w' => 60.0],
            ];
        }

        return $control;
    }

    /**
     * Catmull-Rom centripetico sobre una polilinea cerrada.
     */
    private static function sampleSpline(array $control): array
    {
        $n = count($control);
        $points = [];
        $steps = self::SAMPLES_PER_SEGMENT;

        for ($i = 0; $i < $n; $i++) {
            $p0 = $control[($i - 1 + $n) % $n];
            $p1 = $control[$i];
            $p2 = $control[($i + 1) % $n];
            $p3 = $control[($i + 2) % $n];

            $t0 = 0.0;
            $t1 = $t0 + self::knot($p0, $p1);
            $t2 = $t1 + self::knot($p1, $p2);
            $t3 = $t2 + self::knot($p2, $p3);

            for ($s = 0; $s < $steps; $s++) {
                $t = $t1 + (($t2 - $t1) * ($s / $steps));
                $points[] = self::catmullRom($p0, $p1, $p2, $p3, $t0, $t1, $t2, $t3, $t);
            }
        }

        return $points;
    }

    private static function knot(array $a, array $b): float
    {
        $dx = $b['x'] - $a['x'];
        $dy = $b['y'] - $a['y'];

        return sqrt(max(($dx * $dx) + ($dy * $dy), 1e-6));
    }

    private static function catmullRom(
        array $p0, array $p1, array $p2, array $p3,
        float $t0, float $t1, float $t2, float $t3, float $t
    ): array {
        $a1 = self::lerpPoint($p0, $p1, ($t1 - $t) / ($t1 - $t0), ($t - $t0) / ($t1 - $t0));
        $a2 = self::lerpPoint($p1, $p2, ($t2 - $t) / ($t2 - $t1), ($t - $t1) / ($t2 - $t1));
        $a3 = self::lerpPoint($p2, $p3, ($t3 - $t) / ($t3 - $t2), ($t - $t2) / ($t3 - $t2));

        $b1 = self::lerpPoint($a1, $a2, ($t2 - $t) / ($t2 - $t0), ($t - $t0) / ($t2 - $t0));
        $b2 = self::lerpPoint($a2, $a3, ($t3 - $t) / ($t3 - $t1), ($t - $t1) / ($t3 - $t1));

        return self::lerpPoint($b1, $b2, ($t2 - $t) / ($t2 - $t1), ($t - $t1) / ($t2 - $t1));
    }

    private static function lerpPoint(array $a, array $b, float $wa, float $wb): array
    {
        return [
            'x' => ($a['x'] * $wa) + ($b['x'] * $wb),
            'y' => ($a['y'] * $wa) + ($b['y'] * $wb),
        ];
    }

    private static function findSegment(array $cum, float $distance, int $n): int
    {
        $lo = 0;
        $hi = $n - 1;

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($distance < $cum[$mid + 1]) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }

        return $lo;
    }
}
