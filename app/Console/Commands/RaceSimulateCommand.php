<?php

namespace App\Console\Commands;

use App\Events\RaceResultsUpdated;
use App\Events\RaceStateUpdated;
use App\Models\Player;
use App\Models\RaceCommand;
use App\Models\RaceResult;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\PhysicsService;
use App\Services\TrackService;
use App\Services\WeatherService;
use Illuminate\Console\Command;

class RaceSimulateCommand extends Command
{
    protected $signature = 'race:simulate {roomId}';
    protected $description = 'Corre el bucle de fisica y broadcast de una sala de carrera';

    private const TICK_MS = 100;

    /** Segundos de gracia despues de que gana el primero para que terminen los demas. */
    private const FINISH_GRACE_TICKS = 300;

    /** Longitud en unidades de mundo de un sector: el rodillo de incidentes va por sector. */
    private const SECTOR_LENGTH = 400;

    /** Cuenta regresiva con las luces de salida antes de que larguen los autos. */
    private const COUNTDOWN_MS = 16000;

    /** Probabilidad de salirse de la pista, relativa al riesgo del clima. */
    private const OFF_TRACK_FACTOR = 0.06;

    private const POINTS = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];

    private bool $running = true;
    private int $seq = 0;
    private int $lastCommandId = 0;
    private int $tickCount = 0;
    private int $nextLaneIndex = 0;
    private array $track = [];
    private array $weather = [];

    /** Cronometraje por piloto: vueltas, bandera y tiempos. */
    private array $timing = [];

    /** Roster de la sala, se reenvia solo cuando cambia. */
    private array $roster = [];
    private bool $rosterDirty = true;

    private int $lapCount = 2;
    private int $finishCounter = 0;
    private ?int $lastFinishTick = null;
    private bool $raceFinished = false;

    /** staging -> countdown -> racing -> finished */
    private string $phase = 'staging';
    private int $countdownStartTick = 0;

    public function handle()
    {
        $roomId = $this->argument('roomId');
        $room = Room::with(['members.player', 'track'])->findOrFail($roomId);

        $this->info("Iniciando simulacion para sala: {$room->name} (ID: {$roomId})");
        $this->info("Presiona Ctrl+C para detener.");

        $this->track = TrackService::build($room->track->geometry, (float) $room->track->lap_length_m);
        $this->lapCount = max(1, (int) $room->lap_count);
        $this->weather = WeatherService::fromSeed((int) $room->weather_seed);
        $this->info("Circuito: {$room->track->name} ({$this->track['length']} unidades de mundo, {$this->lapCount} vueltas)");
        $this->info("Clima: {$this->weather['name']} (agarre {$this->weather['wetness']}, riesgo {$this->weather['risk']})");

        $room->forceFill(['status' => 'racing'])->saveQuietly();

        // Estado inicial de los autos en memoria (orden aleatorio para la grilla)
        $carsState = [];
        $drivers = $this->driverIds($room);
        shuffle($drivers);
        foreach ($drivers as $playerId) {
            $carsState[$playerId] = $this->freshCar($playerId);
        }

        // Recuperar ultimo comando por si el proceso se reinicio
        $last = RaceCommand::where('room_id', $room->id)->orderByDesc('id')->first();
        $this->lastCommandId = $last?->id ?? 0;

        // Staging: los autos quedan en la parrilla esperando al host
        $this->countdownStartTick = $this->tickCount;
        $this->info("En parrilla: los autos esperan la orden del host (comando 'start')");

        // Bucle a 10Hz
        while ($this->running) {
            $startTime = microtime(true);
            $this->seq++;
            $this->tickCount++;

            // Cada segundo, sumamos a los jugadores que se unieron despues de arrancar
            if ($this->tickCount % 10 === 1) {
                $this->syncMembers($room, $carsState);
            }

            // 1. Leer comandos nuevos del buzon
            try {
                $this->applyCommands($room, $carsState);
            } catch (\Throwable $e) {
                // Un comando roto no debe tirar toda la carrera
                fwrite(STDERR, "comando ignorado: ".$e->getMessage()."\n");
            }

            // 1b. Las luces de salida: cuando se apagan, largan los autos
            if ($this->phase === 'countdown'
                && ($this->tickCount - $this->countdownStartTick) * self::TICK_MS >= self::COUNTDOWN_MS
            ) {
                $this->phase = 'racing';
                $this->info('Salida: los autos largan');
            }

            // 2. Calcular fisica y armar payload
            $payload = [];
            foreach ($carsState as $playerId => &$car) {
                if (! $this->raceFinished && $this->phase === 'racing') {
                    $previousLaps = (int) ($car['laps'] ?? 0);
                    $car = PhysicsService::tickCar($car, $this->track, $this->weather);

                    // Rodillo de incidentes: una vez por sector de pista
                    $sector = (int) floor(($car['distance'] ?? 0) / self::SECTOR_LENGTH);
                    $lastSector = (int) ($this->timing[$playerId]['sector'] ?? $sector);

                    if ($sector !== $lastSector) {
                        $this->timing[$playerId]['sector'] = $sector;
                        $car = $this->rollIncident($playerId, $car);
                    }

                    // El patinazo se va agotando y el auto vuelve a su carril
                    if (($car['grip_loss_ticks_left'] ?? 0) > 0) {
                        $car['grip_loss_ticks_left'] = $car['grip_loss_ticks_left'] - 1;
                    }

                    $car['slide'] = round((float) ($car['slide'] ?? 0.0) * 0.92, 2);
                    if (abs($car['slide']) < 0.5) {
                        $car['slide'] = 0.0;
                    }

                    // Cruzó la meta: cerramos el tiempo de la vuelta
                    if ((int) ($car['laps'] ?? 0) > $previousLaps) {
                        $this->closeLap($playerId);
                    }

                    // El KERS dura 6 segundos = 60 ticks a 10Hz
                    if ($car['boost_active'] ?? false) {
                        $car['boost_ticks_left'] = ($car['boost_ticks_left'] ?? 0) - 1;
                        if ($car['boost_ticks_left'] <= 0) {
                            $car['boost_active'] = false;
                        }
                    }

                    // Bandera a cuadros
                    if ((int) ($car['laps'] ?? 0) >= $this->lapCount && ! ($car['retired'] ?? false) && ! ($this->timing[$playerId]['finished'] ?? false)) {
                        $this->registerFinish($room, $playerId);
                    }
                }

                $timing = $this->timing[$playerId] ?? [];

                $payload[] = [
                    $playerId,
                    (int) round($car['x'] * 8),
                    (int) round($car['y'] * 8),
                    (int) round(TrackService::normalizeAngle((float) $car['angle']) * (256 / 360)),
                    (int) $car['speed'],
                    $car['gear'],
                    match ($car['mode']) { 'conserve' => 0, 'aggressive' => 2, default => 1 },
                    $car['boost_active'] ? 1 : 0,
                    (int) $car['laps'],
                    (int) $car['boost_charges'],
                    (int) ($timing['last_lap_ms'] ?? 0),
                    (int) ($timing['best_lap_ms'] ?? 0),
                    (int) ($timing['finish_position'] ?? 0),
                    (int) ($car['distance'] ?? 0),
                    ($car['sliding'] ?? false) ? 1 : 0,
                    ($car['retired'] ?? false) ? 1 : 0,
                    (int) round($car['fuel'] ?? 100),
                    (int) round($car['tire_temp'] ?? 45),
                ];
            }
            unset($car);

            $this->safeBroadcast(new RaceStateUpdated(
                $room->id,
                $payload,
                $this->seq,
                $this->rosterDirty ? $this->buildRoster($room) : [],
                $this->phase,
                $this->countdownRemaining()
            ));
            $this->rosterDirty = false;
            // 3. Fin de carrera
            if (! $this->raceFinished && $this->shouldFinishRace($carsState)) {
                $this->finishRace($room, $carsState);
            }

            $elapsed = (microtime(true) - $startTime) * 1000000;
            $sleep = 100000 - $elapsed;
            if ($sleep > 0) {
                usleep($sleep);
            }

            if ($this->seq % 10 === 0) {
                $room->forceFill(['sim_last_beat_at' => now()])->saveQuietly();
            }
        }
    }

    /**
     * Milisegundos que faltan para que se apaguen las luces.
     */
    private function countdownRemaining(): int
    {
        if ($this->phase !== 'countdown') {
            return 0;
        }

        $elapsed = ($this->tickCount - $this->countdownStartTick) * self::TICK_MS;

        return max(0, self::COUNTDOWN_MS - $elapsed);
    }

    /**
     * Un broadcast caido no puede terminar la carrera: lo registramos y seguimos.
     */
    private function safeBroadcast($event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            fwrite(STDERR, "broadcast fallido: ".$e->getMessage()."\n");
        }
    }

    /**
     * Ids de los pilotos que maneja autos (los espectadores no corren).
     */
    private function driverIds(Room $room): array
    {
        return $room->members()
            ->whereIn('role', ['host', 'player'])
            ->pluck('player_id')
            ->all();
    }

    /**
     * Un jugador maneja auto solo si es miembro de la sala y no es espectador.
     */
    private function isDriver(Room $room, int $playerId): bool
    {
        return $room->members()
            ->where('player_id', $playerId)
            ->whereIn('role', ['host', 'player'])
            ->exists();
    }

    private function applyCommands(Room $room, array &$carsState): void
    {
        $commands = RaceCommand::where('room_id', $room->id)
            ->where('id', '>', $this->lastCommandId)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($commands as $command) {
            $this->lastCommandId = max($this->lastCommandId, $command->id);

            // Si el jugador no esta en la sala todavia, lo creamos en vez de romper
            if (! isset($carsState[$command->player_id])) {
                if (! $this->isDriver($room, $command->player_id)) {
                    // No es miembro de la sala: el comando se ignora
                    fwrite(STDERR, "comando ignorado: el jugador {$command->player_id} no esta en la sala".PHP_EOL);
                    $command->forceFill(['applied' => true])->saveQuietly();
                    continue;
                }

                $carsState[$command->player_id] = $this->freshCar($command->player_id);
            }

            $car = &$carsState[$command->player_id];

            if ($command->type === 'mode') {
                $car['mode'] = $command->value;
            }

            if ($command->type === 'boost'
                && ! ($car['boost_active'] ?? false)
                && ($car['boost_charges'] ?? 0) > 0
            ) {
                $car['boost_charges'] = ($car['boost_charges'] ?? 0) - 1;
                $car['boost_active'] = true;
                $car['boost_ticks_left'] = 60; // 6 segundos a 10Hz
            }

            if ($command->type === 'start') {
                // Dar la salida solo aplica si estamos en la parrilla
                if ($this->phase === 'staging') {
                    $this->phase = 'countdown';
                    $this->countdownStartTick = $this->tickCount;
                    $this->info("Luces de salida: la carrera arranca en " . round(self::COUNTDOWN_MS / 1000, 1) . " s");
                }
            }

            if ($command->type === 'restart') {
                $this->restartRace($room, $carsState);
            }

            $command->forceFill(['applied' => true])->saveQuietly();
        }
    }

    private function syncMembers(Room $room, array &$carsState): void
    {
        $current = $this->driverIds($room);

        foreach ($current as $playerId) {
            if (! isset($carsState[$playerId])) {
                $carsState[$playerId] = $this->freshCar($playerId);
                $this->rosterDirty = true;
            }
        }

        // Los que se fueron de la sala pierden su auto
        foreach (array_keys($carsState) as $playerId) {
            if (! in_array($playerId, $current, true)) {
                unset($carsState[$playerId]);
                unset($this->timing[$playerId]);
                $this->rosterDirty = true;
            }
        }
    }

    private function freshCar(int $playerId): array
    {
        $index = $this->nextLaneIndex++;
        $offset = PhysicsService::laneOffsetFor($index, (float) $this->track['width']);

        // Escalonado en la pista para que la grilla de salida no se amontone
        $distance = $index * 12.0;

        $this->timing[$playerId] = [
            'lap_start_tick' => $this->tickCount,
            'start_tick' => $this->tickCount,
            'last_lap_ms' => 0,
            'best_lap_ms' => null,
            'finished' => false,
            'finish_position' => null,
            'total_time_ms' => null,
            'sector' => 0,
        ];

        return PhysicsService::initialCar($playerId, $this->track, $offset, $distance);
    }

    /**
     * Rodillo de incidentes al entrar a un sector nuevo: en pista mojada o
     * con lluvia, el modo agresivo puede patinar o salirse de la pista.
     */
    private function rollIncident(int $playerId, array $car): array
    {
        if ($car['retired'] ?? false) {
            return $car;
        }

        $risk = (float) ($this->weather['risk'] ?? 0.0);

        if ($risk <= 0) {
            return $car;
        }

        $mode = $car['mode'] ?? 'normal';
        $factor = match ($mode) {
            'aggressive' => 1.0,
            'normal'     => 0.25,
            default      => 0.05,
        };

        $skidChance = $risk * $factor;
        $offChance = $risk * self::OFF_TRACK_FACTOR * ($mode === 'aggressive' ? 1.0 : 0.15);

        // Llantas calientes patinan mas: a mas temperatura, mas riesgo
        $tireTemp = (float) ($car['tire_temp'] ?? 45.0);
        if ($tireTemp > 78.0) {
            $skidChance *= 1.0 + (($tireTemp - 78.0) / 40.0);
        }

        if (random_int(1, 10000) <= $offChance * 10000) {
            $car['retired'] = true;
            $car['grip_loss_ticks_left'] = 0;
            $car['slide'] = 0.0;
            $car['speed'] = 0.0;
            fwrite(STDERR, "DNF: piloto {$playerId} se salio de la pista".PHP_EOL);
            return $car;
        }

        if (random_int(1, 10000) <= $skidChance * 10000) {
            $car['grip_loss_ticks_left'] = 25;
            $car['slide'] = random_int(0, 1) === 0 ? -22.0 : 22.0;
            fwrite(STDERR, "Patinazo: piloto {$playerId} perdio agarre".PHP_EOL);
        }
        return $car;
    }

    /**
     * Cierra el cronometraje de la vuelta que acaba de completar el piloto.
     */
    private function closeLap(int $playerId): void
    {
        if (! isset($this->timing[$playerId])) {
            return;
        }

        $lapMs = ($this->tickCount - $this->timing[$playerId]['lap_start_tick']) * self::TICK_MS;

        $this->timing[$playerId]['last_lap_ms'] = $lapMs;
        $this->timing[$playerId]['lap_start_tick'] = $this->tickCount;

        if ($this->timing[$playerId]['best_lap_ms'] === null || $lapMs < $this->timing[$playerId]['best_lap_ms']) {
            $this->timing[$playerId]['best_lap_ms'] = $lapMs;
        }
    }

    private function registerFinish(Room $room, int $playerId): void
    {
        $this->timing[$playerId]['finished'] = true;
        $this->timing[$playerId]['finish_position'] = ++$this->finishCounter;
        $this->timing[$playerId]['total_time_ms'] = ($this->tickCount - $this->timing[$playerId]['start_tick']) * self::TICK_MS;

        RoomMember::where('room_id', $room->id)
            ->where('player_id', $playerId)
            ->update([
                'finished_at' => now(),
                'finish_position' => $this->timing[$playerId]['finish_position'],
                'total_time_ms' => $this->timing[$playerId]['total_time_ms'],
                'best_lap_ms' => $this->timing[$playerId]['best_lap_ms'],
                'fastest_lap' => false,
            ]);

        $this->lastFinishTick = $this->tickCount;

        $this->info("Bandera: piloto {$playerId} termino en el puesto {$this->finishCounter}");
    }

    private function shouldFinishRace(array $carsState): bool
    {
        if (empty($carsState)) {
            return false;
        }

        $finished = 0;
        $retired = 0;
        $total = 0;

        foreach ($carsState as $playerId => $car) {
            $total++;
            if ($this->timing[$playerId]['finished'] ?? false) {
                $finished++;
            } elseif ($car['retired'] ?? false) {
                $retired++;
            }
        }

        if ($finished === 0) {
            // Si todos abandonaron (DNF) la carrera tambien termina
            return $total > 0 && ($finished + $retired) === $total;
        }

        if ($finished === $total) {
            return true;
        }

        // El ganador cruzo la meta: damos 30 segundos para que terminen los demas
        return $this->lastFinishTick !== null
            && ($this->tickCount - $this->lastFinishTick) >= self::FINISH_GRACE_TICKS;
    }

    private function finishRace(Room $room, array $carsState): void
    {
        $this->raceFinished = true;
        $this->phase = 'finished';

        $entries = [];
        foreach ($carsState as $playerId => $car) {
            $timing = $this->timing[$playerId] ?? [];

            $entries[] = [
                'player_id' => $playerId,
                'finished' => (bool) ($timing['finished'] ?? false),
                'retired' => (bool) ($car['retired'] ?? false),
                'finish_position' => $timing['finish_position'] ?? null,
                'laps' => (int) ($car['laps'] ?? 0),
                'distance' => (float) ($car['distance'] ?? 0),
                'best_lap_ms' => $timing['best_lap_ms'] ?? null,
                'total_time_ms' => $timing['total_time_ms'] ?? null,
            ];
        }

        usort($entries, function ($a, $b) {
            if ($a['finished'] && $b['finished']) {
                return $a['finish_position'] <=> $b['finish_position'];
            }

            if ($a['finished'] !== $b['finished']) {
                return $a['finished'] ? -1 : 1;
            }

            // Los que abandonaron (DNF) van al final
            if ($a['retired'] !== $b['retired']) {
                return $a['retired'] ? 1 : -1;
            }

            if ($a['laps'] !== $b['laps']) {
                return $b['laps'] <=> $a['laps'];
            }

            return $b['distance'] <=> $a['distance'];
        });

        // Vuelta rapida de la carrera
        $fastestPlayerId = null;
        $fastestMs = null;
        foreach ($entries as $entry) {
            if ($entry['best_lap_ms'] === null) {
                continue;
            }

            if ($fastestMs === null || $entry['best_lap_ms'] < $fastestMs) {
                $fastestMs = $entry['best_lap_ms'];
                $fastestPlayerId = $entry['player_id'];
            }
        }

        $results = [];
        $position = 0;

        foreach ($entries as $entry) {
            $position++;
            $classified = $entry['finished'];
            $points = $classified ? (self::POINTS[$position] ?? 0) : 0;
            $isFastest = $fastestPlayerId !== null && $fastestPlayerId === $entry['player_id'];

            if ($isFastest && $classified) {
                $points++;
            }

            RaceResult::updateOrCreate(
                ['room_id' => $room->id, 'player_id' => $entry['player_id']],
                [
                    'position' => $position,
                    'points' => $points,
                    'laps_completed' => $entry['laps'],
                    'best_lap_ms' => $entry['best_lap_ms'],
                    'total_time_ms' => $entry['total_time_ms'],
                    'fastest_lap' => $isFastest && $classified,
                    'classified' => $classified,
                ]
            );

            RoomMember::where('room_id', $room->id)
                ->where('player_id', $entry['player_id'])
                ->update([
                    'finish_position' => $position,
                    'best_lap_ms' => $entry['best_lap_ms'],
                    'total_time_ms' => $entry['total_time_ms'],
                    'fastest_lap' => $isFastest && $classified,
                ]);

            $player = Player::find($entry['player_id']);

            $results[] = [
                'player_id' => $entry['player_id'],
                'position' => $position,
                'points' => $points,
                'laps' => $entry['laps'],
                'best_lap_ms' => $entry['best_lap_ms'],
                'total_time_ms' => $entry['total_time_ms'],
                'fastest_lap' => $isFastest && $classified,
                'classified' => $classified,
                'retired' => $entry['retired'],
                'display_name' => $player?->display_name ?? ('Piloto-' . $entry['player_id']),
            ];
        }

        $room->forceFill(['status' => 'finished'])->saveQuietly();

        $this->info("Carrera finalizada. Clasificacion: ".json_encode(array_column($results, 'position')));

        $this->safeBroadcast(new RaceResultsUpdated($room->id, $results, 'finished'));
    }

    private function restartRace(Room $room, array &$carsState): void
    {
        $this->raceFinished = false;
        $this->finishCounter = 0;
        $this->lastFinishTick = null;
        $this->nextLaneIndex = 0;
        $this->timing = [];

        RaceResult::where('room_id', $room->id)->delete();

        RoomMember::where('room_id', $room->id)->update([
            'finished_at' => null,
            'finish_position' => null,
            'total_time_ms' => null,
            'best_lap_ms' => null,
            'fastest_lap' => false,
        ]);

        $carsState = [];
        $drivers = $this->driverIds($room);
        shuffle($drivers);
        foreach ($drivers as $playerId) {
            $carsState[$playerId] = $this->freshCar($playerId);
        }

        $room->forceFill(['status' => 'racing'])->saveQuietly();
        $this->rosterDirty = true;

        // La carrera vuelve a la parrilla: el host decide cuando largar
        $this->phase = 'staging';
        $this->countdownStartTick = $this->tickCount;

        $this->info("Carrera reiniciada: en parrilla, esperando al host");

        $this->safeBroadcast(new RaceResultsUpdated($room->id, [], 'staging'));
    }

    private function buildRoster(Room $room): array
    {
        $roster = [];

        // Consulta fresca: la relacion cargada al arrancar queda vieja
        foreach ($room->members()->with('player')->get() as $member) {
            $roster[] = [
                'id' => $member->player_id,
                'name' => $member->player?->display_name ?? ('Piloto-' . $member->player_id),
                'role' => $member->role,
                'livery' => $member->player?->livery ?? null,
            ];
        }

        return $roster;
    }
}
