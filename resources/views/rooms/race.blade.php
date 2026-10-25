<!DOCTYPE html>
<html lang="es" class="bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $room->name }} - F1 Manager</title>

    <script src="https://cdn.jsdelivr.net/npm/phaser@3.60.0/dist/phaser.min.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        @php
            $trackData = \App\Services\TrackService::build($room->track->geometry, (float) $room->track->lap_length_m);
            $trackPath = $trackData['points'];
            $trackWidth = $trackData['width'];
            $totalLaps = (int) ($room->lap_count ?? 10);
            $weather = \App\Services\WeatherService::fromSeed((int) $room->weather_seed);
        @endphp

        window.__ROOM__ = {
            id: {{ $room->id }},
            player_id: {{ auth('player')->id() }},
            host_player_id: {{ $room->host_player_id ?? 'null' }}
        };
        window.__WEATHER__ = @json($weather);
        window.__ROSTER__ = @json($roster);
        window.__CHAT__ = @json($chat);
        window.__TRACK__ = {
            name: @json($room->track->name),
            path: @json($trackPath),
            width: @json($trackWidth),
            laps: @json($totalLaps)
        };
        window.__BROADCAST__ = {
            key: @json(env('BROADCAST_PUBLIC_KEY') ?: config('broadcasting.connections.reverb.key')),
            host: @json(env('BROADCAST_PUBLIC_HOST') ?: config('broadcasting.connections.reverb.options.host')),
            port: @json(env('BROADCAST_PUBLIC_PORT') ?: config('broadcasting.connections.reverb.options.port')),
            scheme: @json(env('BROADCAST_PUBLIC_SCHEME') ?: config('broadcasting.connections.reverb.options.scheme')),
        };
    </script>
</head>
<body class="min-h-screen overflow-hidden">
    <div id="game-container" class="w-screen h-screen"></div>

    <div style="position: fixed; top: 16px; left: 16px; z-index: 9999; pointer-events: none;">
        <div style="background: rgba(2,6,23,.65); backdrop-filter: blur(8px); padding: 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,.1);">
            <h1 style="font-size: 20px; font-weight: 800; font-style: italic; color: #38bdf8; text-decoration: underline;">{{ $room->name }}</h1>
            <p style="font-size: 12px; color: #94a3b8;">Circuito: {{ $room->track->name }}</p>
            <div id="status-tag" style="margin-top: 8px; font-size: 10px; font-weight: 700; letter-spacing: .15em; color: #eab308;">CONECTANDO...</div>
            <div id="hud-weather" style="margin-top: 10px; font-size: 13px; font-weight: 700; color: #e2e8f0;"></div>
            <div id="hud-speed" style="margin-top: 4px; font-family: monospace; font-size: 13px; font-weight: 700; color: #22c55e; white-space: nowrap;">VEL: --</div>
            <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 5px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 9px; font-weight: 700; color: #94a3b8; width: 52px;">NAFTA</span>
                    <div style="flex: 1; height: 7px; background: #1e293b; border-radius: 4px; overflow: hidden;">
                        <div id="fuel-bar" style="height: 100%; width: 100%; background: #22c55e; transition: width .4s, background .4s;"></div>
                    </div>
                    <span id="fuel-pct" style="font-size: 9px; font-family: monospace; color: #94a3b8; width: 26px; text-align: right;">100</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 9px; font-weight: 700; color: #94a3b8; width: 52px;">LLANTAS</span>
                    <div style="flex: 1; height: 7px; background: #1e293b; border-radius: 4px; overflow: hidden;">
                        <div id="tire-bar" style="height: 100%; width: 45%; background: #38bdf8; transition: width .4s, background .4s;"></div>
                    </div>
                    <span id="tire-pct" style="font-size: 9px; font-family: monospace; color: #94a3b8; width: 26px; text-align: right;">45</span>
                </div>
            </div>
        </div>
    </div>

    <div style="position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; gap: 8px;">
        <button id="mute-btn" title="Silenciar sonido" style="background: rgba(30,41,59,.85); padding: 8px 14px; border-radius: 6px; font-size: 14px; color: #fff; border: 1px solid rgba(255,255,255,.05); cursor: pointer;">🔊</button>
        <button id="start-btn" style="display: none; background: #22c55e; padding: 8px 18px; border-radius: 6px; font-size: 12px; font-weight: 800; color: #052e16; border: none; cursor: pointer;">
            LARGAR CARRERA
        </button>
        <button id="restart-btn" style="display: none; background: rgba(251,191,36,.9); padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 800; color: #0f172a; border: none; cursor: pointer;">
            Reiniciar carrera
        </button>
        <a href="{{ route('lobby') }}" style="background: rgba(30,41,59,.85); padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 700; color: #fff; border: 1px solid rgba(255,255,255,.05); text-decoration: none;">
            Abandonar
        </a>
    </div>

    <!-- Controles de audio (muted/cancelados hasta que se clickea) -->
    <div class="controls-right" style="position: fixed; bottom: 80px; right: 16px; z-index: 9999; display: none;">
        <div style="background: rgba(2,6,23,.85); border: 1px solid rgba(255,255,255,.15); border-radius: 12px; padding: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.6);">
            <div style="font-size: 11px; color: #94a3b8; text-align: center; margin-bottom: 6px;">AUDIO</div>
            <button id="mute-btn" style="background: rgba(30,41,59,.85); padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 700; color: #fff; border: 1px solid rgba(255,255,255,.05); cursor: pointer; display: flex; align-items: center; gap: 6px;">
                <span>🔊</span>
                <span id="mute-text">ON</span>
            </button>
        </div>
    </div>

    <!-- Tabla de posiciones -->
    <div style="position: fixed; top: 64px; right: 16px; z-index: 9999; width: 260px; pointer-events: none;">
        <div style="background: rgba(2,6,23,.72); backdrop-filter: blur(8px); border-radius: 10px; border: 1px solid rgba(255,255,255,.1); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.5);">
            <div style="padding: 9px 12px; font-size: 10px; font-weight: 800; letter-spacing: .18em; color: #38bdf8; border-bottom: 1px solid rgba(255,255,255,.1);">POSICIONES</div>
            <div id="leaderboard"></div>
        </div>
    </div>

    <!-- Controles del Manager -->
    <div style="position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 9999;">
        <div style="background: rgba(2,6,23,.9); border: 1px solid rgba(255,255,255,.15); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 12px; align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,.6);">
            <div style="display: flex; gap: 8px;" id="mode-buttons">
                <button data-mode="conserve" class="mode-btn" style="padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 14px; background: #334155; color: #fff; border: none; cursor: pointer;">Conservar</button>
                <button data-mode="normal" class="mode-btn" style="padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 14px; background: #059669; color: #fff; border: none; cursor: pointer;">Normal</button>
                <button data-mode="aggressive" class="mode-btn" style="padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 14px; background: #334155; color: #fff; border: none; cursor: pointer;">Agresivo</button>
            </div>
            <button id="boost-btn" style="padding: 8px 24px; border-radius: 8px; font-weight: 700; font-size: 14px; background: #f59e0b; color: #0f172a; border: none; cursor: pointer;">
                BOOST (<span id="boost-count">3</span>)
            </button>
            <div id="cmd-status" style="font-size: 10px; color: #94a3b8; font-family: monospace; height: 12px;"></div>
        </div>
    </div>

    <!-- Chat de sala -->
    <div style="position: fixed; bottom: 24px; left: 16px; z-index: 9999; width: 300px;">
        <div style="background: rgba(2,6,23,.85); border: 1px solid rgba(255,255,255,.12); border-radius: 10px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.5);">
            <div style="padding: 8px 12px; font-size: 10px; font-weight: 800; letter-spacing: .18em; color: #38bdf8; border-bottom: 1px solid rgba(255,255,255,.1);">CHAT DE SALA</div>
            <div id="chat-messages" style="height: 160px; overflow-y: auto; padding: 8px 12px; font-size: 12px; display: flex; flex-direction: column; gap: 4px;"></div>
            <form id="chat-form" style="display: flex; border-top: 1px solid rgba(255,255,255,.1);">
                <input id="chat-input" maxlength="200" placeholder="Escribe un mensaje..." autocomplete="off"
                       style="flex: 1; background: transparent; border: none; outline: none; padding: 8px 12px; font-size: 12px; color: #e2e8f0;">
                <button type="submit" style="background: #0284c7; border: none; padding: 0 14px; color: #fff; font-weight: 700; cursor: pointer;">➤</button>
            </form>
        </div>
    </div>

    <!-- Luces de salida -->
    <div id="start-lights" style="position: fixed; top: 11%; left: 50%; transform: translateX(-50%); z-index: 9998; display: none; gap: 12px; padding: 12px 18px; background: rgba(2,6,23,.88); border-radius: 999px; border: 1px solid rgba(255,255,255,.12); box-shadow: 0 10px 40px rgba(0,0,0,.6);">
        @for ($i = 0; $i < 5; $i++)
            <div class="start-light" style="width: 32px; height: 32px; border-radius: 50%; background: #1f2937; box-shadow: inset 0 0 6px rgba(0,0,0,.8); transition: background .08s linear, box-shadow .08s linear;"></div>
        @endfor
    </div>

    <!-- Overlay de resultados -->
    <div id="results-overlay" style="display: none; position: fixed; inset: 0; z-index: 10000; background: rgba(2,6,23,.9); backdrop-filter: blur(8px); align-items: center; justify-content: center;">
        <div style="width: min(720px, 92vw); max-height: 88vh; overflow: auto; background: rgba(2,6,23,.96); border: 1px solid rgba(255,255,255,.12); border-radius: 16px; padding: 28px; box-shadow: 0 25px 80px rgba(0,0,0,.8);">
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 20px;">
                <h2 style="font-size: 26px; font-weight: 800; font-style: italic; color: #38bdf8; text-decoration: underline;">RESULTADOS</h2>
                <span style="font-size: 12px; color: #94a3b8;">{{ $room->track->name }} &middot; {{ $totalLaps }} vueltas</span>
            </div>
            <div id="podium" style="display: flex; gap: 12px; align-items: flex-end; justify-content: center; margin-bottom: 24px;"></div>
            <div id="results-table"></div>
        </div>
    </div>

    <script type="module">
        const statusTag = document.getElementById('status-tag');
        const cmdStatus = document.getElementById('cmd-status');
        const hudSpeed = document.getElementById('hud-speed');
        const boostBtn = document.getElementById('boost-btn');
        const boostCount = document.getElementById('boost-count');
        const leaderboardEl = document.getElementById('leaderboard');
        const hudWeather = document.getElementById('hud-weather');
        const fuelBar = document.getElementById('fuel-bar');
        const fuelPct = document.getElementById('fuel-pct');
        const tireBar = document.getElementById('tire-bar');
        const tirePct = document.getElementById('tire-pct');
        const resultsOverlay = document.getElementById('results-overlay');
        const podiumEl = document.getElementById('podium');
        const resultsTableEl = document.getElementById('results-table');
        const restartBtn = document.getElementById('restart-btn');
        const startBtn = document.getElementById('start-btn');
        const chatForm = document.getElementById('chat-form');
        const chatInput = document.getElementById('chat-input');
        const chatMessages = document.getElementById('chat-messages');
        const muteBtn = document.getElementById('mute-btn');
        const lightsBox = document.getElementById('start-lights');
        const lightDots = Array.from(document.querySelectorAll('.start-light'));
        const roomId = window.__ROOM__.id;
        const totalLaps = window.__TRACK__.laps;

        const MODES = ['CONSERVAR', 'NORMAL', 'AGRESIVO'];
        const DEFAULT_LIVERY = { primary: '#e10600', secondary: '#111827' };

        const shortestDiff = (a, b) => {
            let d = (a - b) % 360;
            if (d > 180) d -= 360;
            if (d < -180) d += 360;
            return d;
        };

        const liveryOf = (id) => {
            const entry = roster.get(id);
            if (entry && entry.livery && entry.livery.primary) return entry.livery;
            return DEFAULT_LIVERY;
        };

        const hexToInt = (hex) => {
            const clean = (hex || '#e10600').replace('#', '');
            return parseInt(clean, 16);
        };

        const colorOf = (id) => liveryOf(id).primary;

        const formatTime = (ms) => {
            if (!ms || ms < 0) return '--:--.---';
            const m = Math.floor(ms / 60000);
            const s = Math.floor((ms % 60000) / 1000);
            const t = ms % 1000;
            return `${m}:${String(s).padStart(2, '0')}.${String(t).padStart(3, '0')}`;
        };

        const roster = new Map();
        (window.__ROSTER__ || []).forEach((member) => roster.set(member.id, member));
        let lastResults = [];
        let raceStatus = 'racing';

        const updateStatusTag = () => {
            if (!statusTag) return;
            const labels = { finished: 'FINALIZADA', countdown: 'LUZES DE SALIDA', staging: 'PARRILLA', racing: 'RECIBIENDO DATOS' };
            const classes = { finished: 'text-amber-400', countdown: 'text-red-400', staging: 'text-sky-400', racing: 'text-emerald-400' };
            statusTag.textContent = labels[raceStatus] ?? 'RECIBIENDO DATOS';
            statusTag.className = 'mt-2 text-[10px] font-bold uppercase tracking-widest ' + (classes[raceStatus] ?? classes.racing);
        };

        let cmdSeq = 0;
        let boostCharges = 3;
        let optimisticBoost = false;
        let optimisticBoostTimer = null;

        const sendCommand = async (type, value = null) => {
            cmdSeq++;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            try {
                const res = await fetch(`/rooms/${roomId}/commands`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ seq: cmdSeq, type, value }),
                });
                const data = await res.json();
                if (res.status === 429) {
                    cmdStatus.textContent = 'Espera un instante';
                    cmdStatus.className = 'text-[10px] text-amber-400 font-mono h-3';
                } else if (res.ok) {
                    cmdStatus.textContent = `${type}${value ? ': ' + value : ''} OK`;
                    cmdStatus.className = 'text-[10px] text-emerald-400 font-mono h-3';
                    if (type === 'boost' && boostCharges > 0) {
                        boostCharges = Math.max(0, boostCharges - 1);
                        boostCount.textContent = boostCharges;
                        boostBtn.disabled = boostCharges === 0;
                    }
                } else {
                    cmdStatus.textContent = data.error || 'Error';
                    cmdStatus.className = 'text-[10px] text-red-400 font-mono h-3';
                }
            } catch (e) {
                cmdStatus.textContent = 'Sin conexion';
                cmdStatus.className = 'text-[10px] text-red-400 font-mono h-3';
            }
        };

        document.querySelectorAll('.mode-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                window.F1Audio?.click();
                sendCommand('mode', btn.dataset.mode);
            });
        });
        boostBtn.addEventListener('click', () => {
            optimisticBoost = true;
            clearTimeout(optimisticBoostTimer);
            optimisticBoostTimer = setTimeout(() => { optimisticBoost = false; }, 6000);
            window.F1Audio?.click();
            sendCommand('boost');
        });
        if (restartBtn) {
            restartBtn.addEventListener('click', () => {
                window.F1Audio?.click();
                sendCommand('restart');
            });
        }
        if (startBtn) {
            startBtn.addEventListener('click', () => {
                window.F1Audio?.click();
                sendCommand('start');
            });
        }

        // ---------- Chat de sala ----------
        const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        const appendChat = (msg) => {
            const mine = msg.player_id === window.__ROOM__.player_id;
            const line = document.createElement('div');
            line.innerHTML = `<span style="color: #64748b; font-family: monospace; font-size: 10px;">${msg.at}</span> `
                + `<span style="font-weight: 700; color: ${mine ? '#38bdf8' : '#fbbf24'};">${escapeHtml(msg.name)}:</span> `
                + `<span style="color: #cbd5e1;">${escapeHtml(msg.body)}</span>`;
            chatMessages.appendChild(line);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            while (chatMessages.children.length > 100) chatMessages.removeChild(chatMessages.firstChild);
        };

        (window.__CHAT__ || []).forEach(appendChat);

        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const body = chatInput.value.trim();
                if (!body) return;
                chatInput.value = '';
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                try {
                    await fetch(`/rooms/${roomId}/chat`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ body }),
                    });
                } catch (err) {
                    cmdStatus.textContent = 'Chat sin conexion';
                }
            });
        }
        if (muteBtn) {
            const paintMute = () => {
                muteBtn.textContent = window.F1Audio?.isMuted() ? '🔇' : '🔊';
                muteBtn.title = window.F1Audio?.isMuted() ? 'Activar sonido' : 'Silenciar sonido';
            };
            paintMute();
            muteBtn.addEventListener('click', () => {
                window.F1Audio?.toggleMute();
                paintMute();
            });
        }

        const nameOf = (id) => roster.get(id)?.name ?? ('Piloto-' + id);

        // ---------- Luces de salida ----------
        let lightsStarted = false;

        const paintLights = (encendidas) => {
            lightDots.forEach((dot, indice) => {
                const on = indice < encendidas;
                dot.style.background = on ? '#ef4444' : '#1f2937';
                dot.style.boxShadow = on
                    ? '0 0 14px rgba(239,68,68,.9), inset 0 0 4px rgba(255,255,255,.35)'
                    : 'inset 0 0 6px rgba(0,0,0,.8)';
            });
        };

        const showLights = () => {
            lightsBox.style.display = 'flex';
            paintLights(0);
        };

        const hideLights = () => {
            lightsBox.style.display = 'none';
            paintLights(0);
        };

        /**
         * Programa el semaforo: el audio queda al final justo cuando se apagan
         * las luces, que es cuando largan los autos.
         */
        const startStartLights = (remainingMs) => {
            if (lightsStarted) {
                return;
            }

            lightsStarted = true;
            showLights();

            window.F1Audio?.startLights(remainingMs, (indice) => {
                paintLights(indice + 1);
            }, () => {
                paintLights(0);
            });
        };

        if (hudWeather && window.__WEATHER__) {
            hudWeather.textContent = `${window.__WEATHER__.emoji} ${window.__WEATHER__.name}`;
        }

        const renderLeaderboard = (cars) => {
            const rows = cars.map((row) => ({
                id: row[0],
                laps: row[8] ?? 0,
                best: row[11] ?? 0,
                finish: row[12] ?? 0,
                distance: row[13] ?? 0,
                retired: row[15] === 1,
            }));

            rows.sort((a, b) => {
                if (a.finish && b.finish) return a.finish - b.finish;
                if (a.finish !== b.finish) return a.finish ? -1 : 1;
                if (a.retired !== b.retired) return a.retired ? 1 : -1;
                if (a.laps !== b.laps) return b.laps - a.laps;
                return b.distance - a.distance;
            });

            leaderboardEl.innerHTML = rows.map((row, index) => {
                const mine = row.id === window.__ROOM__.player_id;
                const border = mine ? 'border-left: 3px solid ' + colorOf(row.id) + ';' : '';
                const pos = row.finish ? row.finish : index + 1;
                const best = row.best ? formatTime(row.best) : '--:--.---';
                const laps = row.retired
                    ? '<span style="font-size: 10px; color: #ef4444; font-family: monospace; font-weight: 800;">DNF</span>'
                    : `<span style="font-size: 10px; color: #64748b; font-family: monospace;">V${row.laps}</span>`;
                return `
                    <div style="display: flex; align-items: center; gap: 8px; padding: 7px 12px; ${border} background: ${mine ? 'rgba(56,189,248,.10)' : 'transparent'};">
                        <span style="width: 20px; font-family: monospace; font-size: 12px; font-weight: 800; color: ${pos <= 3 ? '#fbbf24' : '#94a3b8'};">${pos}</span>
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: ${colorOf(row.id)}; flex: none;"></span>
                        <span style="flex: 1; font-size: 12px; font-weight: 600; color: #e2e8f0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${nameOf(row.id)}</span>
                        ${laps}
                        <span style="font-size: 10px; color: #64748b; font-family: monospace; width: 62px; text-align: right;">${best}</span>
                    </div>`;
            }).join('');
        };

        const showResults = (results) => {
            // Podio: con tres o mas pilotos se dibuja 2 - 1 - 3 con el 1º en el medio
            const podiumOrder = results.length >= 3 ? [1, 0, 2] : [0, 1];
            const heightByPosition = [112, 88, 68];
            const medals = ['#fbbf24', '#cbd5e1', '#d97706'];

            podiumEl.innerHTML = podiumOrder.map((resultIndex) => {
                const row = results[resultIndex];
                if (!row) return '';
                const medal = medals[row.position - 1] ?? '#94a3b8';
                return `
                    <div style="flex: 1; text-align: center; background: rgba(15,23,42,.9); border: 1px solid ${medal}55; border-radius: 12px; padding: 14px 10px; min-height: ${heightByPosition[row.position - 1] ?? 68}px; display: flex; flex-direction: column; justify-content: flex-end;">
                        <div style="font-size: 22px; font-weight: 800; color: ${medal};">${row.position}º</div>
                        <div style="font-size: 12px; font-weight: 700; color: #e2e8f0; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${row.display_name}</div>
                        <div style="font-size: 11px; color: #22c55e; font-family: monospace; margin-top: 2px;">${row.points} pts</div>
                        <div style="font-size: 10px; color: #64748b; font-family: monospace;">${formatTime(row.total_time_ms)}</div>
                    </div>`;
            }).join('');

            resultsTableEl.innerHTML = `
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="color: #64748b; font-size: 10px; letter-spacing: .12em; text-align: left;">
                            <th style="padding: 6px 4px;">POS</th>
                            <th style="padding: 6px 4px;">PILOTO</th>
                            <th style="padding: 6px 4px;">VUELTAS</th>
                            <th style="padding: 6px 4px;">MEJOR VUELTA</th>
                            <th style="padding: 6px 4px;">TOTAL</th>
                            <th style="padding: 6px 4px; text-align: right;">PTS</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${results.map((row) => `
                            <tr style="border-top: 1px solid rgba(255,255,255,.07); color: ${row.player_id === window.__ROOM__.player_id ? '#38bdf8' : '#cbd5e1'};">
                                <td style="padding: 7px 4px; font-weight: 800;">${row.position}</td>
                                <td style="padding: 7px 4px; font-weight: 600;">
                                    ${row.display_name}
                                    ${row.fastest_lap ? '<span style="font-size: 9px; color: #0f172a; background: #fbbf24; padding: 1px 5px; border-radius: 6px; font-weight: 800; margin-left: 6px;">VUELTA RAPIDA</span>' : ''}
                                    ${row.classified ? '' : (row.retired ? '<span style="font-size: 9px; color: #ef4444; font-weight: 800; margin-left: 6px;">DNF</span>' : '<span style="font-size: 9px; color: #64748b; margin-left: 6px;">(NC)</span>')}
                                </td>
                                <td style="padding: 7px 4px; font-family: monospace;">${row.laps}</td>
                                <td style="padding: 7px 4px; font-family: monospace;">${formatTime(row.best_lap_ms)}</td>
                                <td style="padding: 7px 4px; font-family: monospace;">${formatTime(row.total_time_ms)}</td>
                                <td style="padding: 7px 4px; text-align: right; font-weight: 800; color: #22c55e;">${row.points}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>`;

            resultsOverlay.style.display = 'flex';
        };

        const hideResults = () => {
            resultsOverlay.style.display = 'none';
            podiumEl.innerHTML = '';
            resultsTableEl.innerHTML = '';
        };

        // Al entrar tarde a una carrera ya terminada hay que pedir el estado
        const loadState = async () => {
            try {
                const res = await fetch(`/rooms/${roomId}/state`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();

                if (Array.isArray(data.roster)) {
                    data.roster.forEach((member) => roster.set(member.id, member));
                }

                raceStatus = data.status || 'racing';
                lastResults = data.results || [];

                if (raceStatus === 'finished' && lastResults.length) {
                    showResults(lastResults);
                }

                const isHost = window.__ROOM__.host_player_id === window.__ROOM__.player_id;
                if (restartBtn) restartBtn.style.display = (isHost && raceStatus === 'finished') ? 'inline-block' : 'none';

                updateStatusTag();
            } catch (e) {
                console.warn('no se pudo leer el estado de la carrera', e);
            }
        };

        class RaceScene extends Phaser.Scene {
            constructor() {
                super('RaceScene');
                this.cars = new Map();
                this.playerCar = null;
                this.boostConfirmed = false;
                this.rainDrops = [];
                this.fogLayers = [];
                this.haze = null;
                this.audioState = { boost: false, flag: false, skid: false, retired: false };
            }

            create() {
                const waitingText = this.add.text(20, 20, 'ESPERANDO DATOS DEL SERVIDOR...', { font: '14px Arial', fill: '#ffffff' });
                waitingText.setDepth(40);

                this.buildCarTexture();
                this.drawTrack();
                this.buildWeather();

                this.cameras.main.setZoom(1.4);

                window.Echo.channel(`race.${roomId}`)
                    .listen('.race.state', (data) => {
                        if (waitingText.active) waitingText.destroy();

                        // Fase de la carrera: staging -> countdown -> racing -> finished
                        const fase = data.p ?? 'racing';
                        raceStatus = fase;
                        updateStatusTag();

                        // Botones: largar solo en parrilla, reiniciar solo al final (host)
                        const isHost = window.__ROOM__.host_player_id === window.__ROOM__.player_id;
                        if (startBtn) startBtn.style.display = (isHost && fase === 'staging') ? 'inline-block' : 'none';
                        if (restartBtn) restartBtn.style.display = (isHost && fase === 'finished') ? 'inline-block' : 'none';

                        if (data.r && data.r.length) {
                            data.r.forEach((member) => roster.set(member.id, member));
                        }

                        if (fase === 'staging') {
                            hideResults();
                            hideLights();
                            lightsStarted = false;
                            window.F1Audio?.stopEngine();
                        } else if (fase === 'countdown') {
                            hideResults();
                            window.F1Audio?.startEngine();
                            startStartLights(data.cd ?? 0);
                        } else if (fase === 'racing') {
                            hideResults();
                            hideLights();
                            lightsStarted = false;
                        } else if (fase === 'finished') {
                            hideLights();
                            lightsStarted = false;
                            window.F1Audio?.stopEngine();
                        }

                        this.updateCars(data.c);
                        renderLeaderboard(data.c || []);
                    })
                    .listen('.race.chat', (data) => {
                        appendChat(data);
                    })
                    .listen('.race.results', (data) => {
                        lastResults = data.results || [];
                        raceStatus = data.status;

                        const isHost = window.__ROOM__.host_player_id === window.__ROOM__.player_id;
                        if (startBtn) startBtn.style.display = (isHost && raceStatus === 'staging') ? 'inline-block' : 'none';
                        if (restartBtn) restartBtn.style.display = (isHost && raceStatus === 'finished') ? 'inline-block' : 'none';

                        if (raceStatus === 'finished' && lastResults.length) {
                            showResults(lastResults);
                        } else {
                            hideResults();
                        }

                        updateStatusTag();
                    });

                loadState();

                // El motor y el semaforo se cargan por adelantado
                window.F1Audio?.preload();

                window.Echo.connector.pusher.connection.bind('connected', () => {
                    console.log('WebSocket Conectado via Echo');
                });
            }

            // Sprite del auto generado en runtime: body + detail para tintear por livrea
            buildCarTexture() {
                const g = this.make.graphics({ x: 0, y: 0, add: false });

                // f1-body: carroceria principal (se tiñe con el color primario)
                g.clear();
                g.fillStyle(0xffffff, 1);
                g.fillRect(6, 6, 18, 12);
                g.fillTriangle(24, 9, 42, 12, 42, 6);
                g.fillRect(6, 2, 12, 20);
                g.fillRect(0, 3, 5, 18);
                g.fillRect(21, 0, 5, 24);
                g.generateTexture('f1-body', 44, 24);
                g.destroy();

                // f1-detail: alerones, halo y ruedas (se tiñe con el color secundario)
                const d = this.make.graphics({ x: 0, y: 0, add: false });
                d.clear();
                d.fillStyle(0xffffff, 1);
                d.fillRect(14, 9, 6, 6);
                d.fillRect(20, 0, 7, 6);
                d.fillRect(20, 18, 7, 6);
                d.fillRect(2, 0, 8, 6);
                d.fillRect(2, 18, 8, 6);
                d.generateTexture('f1-detail', 44, 24);
                d.destroy();
            }

            drawTrack() {
                const path = window.__TRACK__.path;
                const width = window.__TRACK__.width;
                const wet = window.__WEATHER__?.is_wet ?? false;
                const g = this.add.graphics();
                g.setDepth(0);

                const pts = path.map(p => new Phaser.Geom.Point(p.x, p.y));

                g.lineStyle(width + 12, wet ? 0x475569 : 0x334155, 1);
                g.strokePoints(pts, true);

                g.lineStyle(width, wet ? 0x131f33 : 0x1e293b, 1);
                g.strokePoints(pts, true);

                g.lineStyle(3, 0x64748b, 0.45);
                for (let i = 0; i < pts.length; i += 14) {
                    g.strokePoints([pts[i], pts[(i + 7) % pts.length]], false);
                }

                // Chevrones: indican el sentido de la carrera
                g.lineStyle(5, 0x475569, 0.9);
                for (let i = 0; i < pts.length; i += 30) {
                    const p = pts[i];
                    const q = pts[(i + 6) % pts.length];
                    const dx = q.x - p.x;
                    const dy = q.y - p.y;
                    const len = Math.hypot(dx, dy) || 1;
                    const ux = dx / len;
                    const uy = dy / len;
                    const nx = -uy;
                    const ny = ux;
                    const size = 12;
                    const tipX = p.x + ux * size;
                    const tipY = p.y + uy * size;
                    g.beginPath();
                    g.moveTo(p.x - ux * size + nx * size, p.y - uy * size + ny * size);
                    g.lineTo(tipX, tipY);
                    g.lineTo(p.x - ux * size - nx * size, p.y - uy * size - ny * size);
                    g.strokePath();
                }

                // Recta de largada
                const p0 = pts[0];
                const p1 = pts[1 % pts.length];
                const dx = p1.x - p0.x;
                const dy = p1.y - p0.y;
                const len = Math.hypot(dx, dy) || 1;
                const nx = -dy / len;
                const ny = dx / len;
                const cells = 12;
                const cell = width / cells;
                for (let i = 0; i < cells; i++) {
                    const t = -width / 2 + cell * (i + 0.5);
                    g.fillStyle(i % 2 === 0 ? 0xffffff : 0x0f172a, 1);
                    g.fillRect(p0.x + nx * t - 5, p0.y + ny * t - 5, 10, 10);
                }
            }

            // Efectos del clima: lluvia, niebla y asfalto mojado
            buildWeather() {
                const weather = window.__WEATHER__;

                if (!weather) {
                    return;
                }

                if (weather.key === 'rain') {
                    const g = this.make.graphics({ x: 0, y: 0, add: false });
                    g.fillStyle(0xbfdbfe, 0.9);
                    g.fillRect(0, 0, 2, 14);
                    g.generateTexture('drop', 2, 14);
                    g.destroy();

                    const count = Math.round(70 + weather.intensity * 130);

                    for (let i = 0; i < count; i++) {
                        const drop = this.add.image(
                            Math.random() * this.scale.width,
                            Math.random() * this.scale.height,
                            'drop'
                        ).setDepth(30);
                        drop.fallSpeed = 700 + Math.random() * 600;
                        this.rainDrops.push(drop);
                    }
                }

                if (weather.key === 'fog') {
                    for (let i = 0; i < 3; i++) {
                        const layer = this.add.rectangle(
                            0, 0,
                            this.scale.width * 1.6,
                            this.scale.height,
                            0xcbd5e1,
                            0.10 + i * 0.04
                        ).setOrigin(0, 0).setDepth(30);
                        layer.driftSpeed = 9 + i * 7;
                        this.fogLayers.push(layer);
                    }

                    this.haze = this.add.rectangle(
                        0, 0, this.scale.width, this.scale.height, 0x94a3b8, 0.12
                    ).setOrigin(0, 0).setDepth(29);
                }
            }

            updateCars(carsData) {
                carsData.forEach((row) => {
                    const id = row[0];
                    const x = row[1] / 8;
                    const y = row[2] / 8;
                    const angle = row[3] * (360 / 256);
                    const speed = row[4];
                    const gear = row[5] ?? 0;
                    const modeIdx = row[6] ?? 1;
                    const mode = MODES[modeIdx] ?? 'NORMAL';
                    const boost = row[7] === 1;
                    const laps = row[8] ?? 0;
                    const charges = row[9] ?? 3;
                    const lastLap = row[10] ?? 0;
                    const bestLap = row[11] ?? 0;
                    const finishPosition = row[12] ?? 0;
                    const sliding = row[14] === 1;
                    const retired = row[15] === 1;
                    const fuel = row[16] ?? 100;
                    const tireTemp = row[17] ?? 45;

                    const isPlayer = window.__ROOM__.player_id === id;
                    const effectiveBoost = boost || (isPlayer && optimisticBoost);

                    // Si el servidor ya confirmo el boost y ahora lo niega, se apaga
                    if (isPlayer) {
                        if (boost) {
                            this.boostConfirmed = true;
                        } else if (this.boostConfirmed) {
                            this.boostConfirmed = false;
                            optimisticBoost = false;
                            clearTimeout(optimisticBoostTimer);
                        }
                    }

                    if (!this.cars.has(id)) {
                        const liver = liveryOf(id);
                        const bodyTint = hexToInt(liver.primary);
                        const detailTint = hexToInt(liver.secondary);

                        const car = this.add.image(x, y, 'f1-body').setOrigin(0.5, 0.5);
                        car.setDepth(10);
                        car.setTint(bodyTint);
                        car.rotationDeg = angle;
                        car.rotation = Phaser.Math.DegToRad(angle);
                        car.kersOn = false;

                        const detail = this.add.image(x, y, 'f1-detail').setOrigin(0.5, 0.5);
                        detail.setDepth(11);
                        detail.setTint(detailTint);
                        car.detailSprite = detail;

                        const label = this.add.text(x, y - 26, nameOf(id), {
                            font: '10px monospace', fill: '#94a3b8'
                        });
                        label.setDepth(20);
                        car.label = label;

                        this.cars.set(id, car);
                        if (isPlayer) {
                            this.playerCar = car;
                        }
                    }

                    const car = this.cars.get(id);
                    const risingEdge = effectiveBoost && !car.kersOn;
                    car.kersOn = effectiveBoost;

                    // Interpolacion suave de la posicion (90ms). El angulo no se
                    // tweenea: si no, al dar la vuelta el auto giraria al reves.
                    if (car.x !== x || car.y !== y) {
                        this.tweens.killTweensOf(car);
                        if (risingEdge) {
                            car.x = x;
                            car.y = y;
                        } else {
                            this.tweens.add({
                                targets: car,
                                x: x,
                                y: y,
                                duration: 90,
                                ease: 'Linear'
                            });
                        }
                    }

                    car.heading = angle;

                    // Color: gris si abandono, naranja si patinando, amarillo con KERS
                    let tint = hexToInt(liveryOf(id).primary);
                    if (retired) {
                        tint = 0x6b7280;
                    } else if (sliding) {
                        tint = 0xf97316;
                    } else if (effectiveBoost) {
                        tint = 0xfbbf24;
                    }
                    car.setTint(tint);
                    car.setScale(effectiveBoost ? 1.3 : 1);

                    // El sprite de detalle acompana al body
                    if (car.detailSprite) {
                        car.detailSprite.x = car.x;
                        car.detailSprite.y = car.y;
                        car.detailSprite.rotation = car.rotation;
                        car.detailSprite.setScale(effectiveBoost ? 1.3 : 1);
                        let detailTint = hexToInt(liveryOf(id).secondary);
                        if (retired) {
                            detailTint = 0x4b5563;
                        } else if (sliding) {
                            detailTint = 0xc2410c;
                        } else if (effectiveBoost) {
                            detailTint = 0xd97706;
                        }
                        car.detailSprite.setTint(detailTint);
                    }

                    if (risingEdge && isPlayer) {
                        this.cameras.main.shake(250, 0.01);
                    }

                    if (isPlayer) {
                        // Motor: la frecuencia sigue a la velocidad, la marcha y el modo
                        window.F1Audio?.engine(speed, gear, modeIdx, boost, retired);

                        if (boost && !this.audioState.boost) {
                            window.F1Audio?.kers();
                        }
                        this.audioState.boost = boost;

                        if (sliding && !this.audioState.skid) {
                            window.F1Audio?.skid();
                        }
                        this.audioState.skid = sliding;

                        if (finishPosition > 0 && !this.audioState.flag) {
                            window.F1Audio?.flag();
                        }
                        this.audioState.flag = finishPosition > 0;

                        if (retired && !this.audioState.retired) {
                            // Si se salio de la pista: chirrido y choque, sin luces
                            window.F1Audio?.retire();
                        }
                        this.audioState.retired = retired;

                        const lapText = retired
                            ? 'DNF'
                            : (finishPosition ? `BANDERA P${finishPosition}` : `VUELTA ${Math.min(laps + 1, totalLaps)}/${totalLaps}`);
                        const times = bestLap
                            ? `  ULT ${formatTime(lastLap)}  MEJOR ${formatTime(bestLap)}`
                            : '';
                        const slideTag = sliding ? '  ¡PATINAJE!' : '';
                        hudSpeed.textContent = `VEL: ${speed} km/h  MODO ${mode}  ${lapText}${slideTag}${times}  KERS ${charges}`;
                        hudSpeed.style.color = retired
                            ? '#ef4444'
                            : (sliding ? '#f97316' : (effectiveBoost ? '#fbbf24' : '#22c55e'));

                        boostCharges = charges;
                        boostCount.textContent = charges;
                        boostBtn.disabled = charges === 0;

                        // Combustible y temperatura de llantas
                        fuelBar.style.width = Math.max(0, fuel) + '%';
                        fuelBar.style.background = fuel > 40 ? '#22c55e' : (fuel > 15 ? '#f59e0b' : '#ef4444');
                        fuelPct.textContent = fuel;
                        fuelPct.style.color = fuel > 40 ? '#94a3b8' : (fuel > 15 ? '#f59e0b' : '#ef4444');
                        tireBar.style.width = Math.max(0, Math.min(100, tireTemp)) + '%';
                        tireBar.style.background = tireTemp > 90 ? '#ef4444' : (tireTemp > 78 ? '#f59e0b' : '#38bdf8');
                        tirePct.textContent = tireTemp;
                        tirePct.style.color = tireTemp > 90 ? '#ef4444' : (tireTemp > 78 ? '#f59e0b' : '#94a3b8');
                    }
                });
            }

            update(time, delta) {
                const cam = this.cameras.main;
                const seconds = delta / 1000;

                this.rainDrops.forEach((drop) => {
                    drop.y += drop.fallSpeed * seconds;
                    drop.x += 90 * seconds;
                    if (drop.y > this.scale.height + 20) {
                        drop.y = -20;
                        drop.x = Math.random() * this.scale.width;
                    }
                });

                this.fogLayers.forEach((layer) => {
                    layer.x -= layer.driftSpeed * seconds;
                    if (layer.x < -this.scale.width) {
                        layer.x = this.scale.width;
                    }
                });

                if (this.haze) {
                    this.haze.setSize(this.scale.width, this.scale.height);
                }

                this.cars.forEach((car) => {
                    if (typeof car.heading === 'number') {
                        car.rotationDeg += shortestDiff(car.heading, car.rotationDeg) * 0.18;
                        car.rotation = Phaser.Math.DegToRad(car.rotationDeg);
                    }
                    if (car.detailSprite) {
                        car.detailSprite.x = car.x;
                        car.detailSprite.y = car.y;
                        car.detailSprite.rotation = car.rotation;
                    }
                    if (car.label) {
                        car.label.setPosition(car.x, car.y - 26);
                    }
                });

                const target = this.playerCar ?? this.cars.values().next().value;

                if (target) {
                    const halfW = cam.width / cam.zoom / 2;
                    const halfH = cam.height / cam.zoom / 2;
                    cam.scrollX += ((target.x - halfW) - cam.scrollX) * 0.08;
                    cam.scrollY += ((target.y - halfH) - cam.scrollY) * 0.08;
                }
            }
        }

        const config = {
            type: Phaser.AUTO,
            parent: 'game-container',
            width: window.innerWidth,
            height: window.innerHeight,
            backgroundColor: window.__WEATHER__?.key === 'cloudy' ? '#0b1220' : '#020617',
            scale: { mode: Phaser.Scale.RESIZE },
            scene: [RaceScene]
        };

        new Phaser.Game(config);
    </script>
</body>
</html>
