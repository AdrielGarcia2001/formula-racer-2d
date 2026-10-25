<!DOCTYPE html>
<html lang="es" class="bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verificacion WebSocket</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Config de Reverb en tiempo de ejecucion: permite apuntar al tunel
        // cambiando solo el .env, sin recompilar assets.
        window.__BROADCAST__ = {
            key: @json(env('BROADCAST_PUBLIC_KEY') ?: config('broadcasting.connections.reverb.key')),
            host: @json(env('BROADCAST_PUBLIC_HOST') ?: config('broadcasting.connections.reverb.options.host')),
            port: @json(env('BROADCAST_PUBLIC_PORT') ?: config('broadcasting.connections.reverb.options.port')),
            scheme: @json(env('BROADCAST_PUBLIC_SCHEME') ?: config('broadcasting.connections.reverb.options.scheme')),
        };
    </script>
</head>
<body class="min-h-screen text-slate-100 font-mono">
    <main class="mx-auto max-w-3xl p-6 space-y-6">
        <h1 class="text-2xl font-bold">Slice 0 &mdash; Verificacion del WebSocket</h1>

        <div class="rounded-lg border border-slate-800 p-4 space-y-1 text-sm">
            <p>Jugador anonimo: <span class="text-sky-300">{{ auth('player')->user()?->display_name ?? '(sin asignar)' }}</span></p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-800 p-4">
                <p class="text-xs text-slate-400">Socket</p>
                <p id="socket-state" class="font-bold text-yellow-400">iniciando</p>
            </div>
            <div class="rounded-lg border border-slate-800 p-4">
                <p class="text-xs text-slate-400">Canal publico</p>
                <p id="public-state" class="font-bold text-yellow-400">iniciando</p>
            </div>
            <div class="rounded-lg border border-slate-800 p-4">
                <p class="text-xs text-slate-400">Canal privado</p>
                <p id="private-state" class="font-bold text-yellow-400">iniciando</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-800 p-4 text-sm space-y-1">
            <p>URL del socket: <span id="socket-url" class="text-slate-400 break-all">-</span></p>
            <p>Eventos recibidos: <span id="count" class="font-bold">0</span></p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button data-ping="/tunnel-check/ping-public" class="rounded bg-emerald-600 px-4 py-2 text-white hover:bg-emerald-500">
                Ping (canal publico)
            </button>
            <button data-ping="/tunnel-check/ping" class="rounded bg-sky-600 px-4 py-2 text-white hover:bg-sky-500">
                Ping (canal privado)
            </button>
            <a href="/tunnel-check" class="rounded border border-slate-700 px-4 py-2 hover:bg-slate-800">Recargar</a>
        </div>

        <pre id="log" class="h-64 overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-emerald-300"></pre>
    </main>

    <script type="module">
        const socketEl = document.getElementById('socket-state');
        const publicEl = document.getElementById('public-state');
        const privateEl = document.getElementById('private-state');
        const countEl = document.getElementById('count');
        const logEl = document.getElementById('log');
        const urlEl = document.getElementById('socket-url');

        const log = (msg) => {
            logEl.textContent += `[${new Date().toLocaleTimeString()}] ${msg}\n`;
            logEl.scrollTop = logEl.scrollHeight;
        };

        const paint = (el, text, color) => {
            el.textContent = text;
            el.className = `font-bold ${color}`;
        };

        const track = (el, label, channelObj) => {
            log(`${label}: inicializando...`);
            
            if (!channelObj) {
                paint(el, 'ERROR', 'text-red-500');
                log(`${label}: canal es undefined`);
                return;
            }

            channelObj.listen('.tunnel.ping', (data) => {
                log(`${label}: EVENTO RECIBIDO -> ${JSON.stringify(data)}`);
                const n = Number(countEl.textContent) + 1;
                countEl.textContent = String(n);
            });

            if (typeof channelObj.here === 'function') {
                log(`${label}: esperando suscripción...`);
                channelObj
                    .here(() => {
                        paint(el, 'suscripto', 'text-emerald-400');
                        log(`${label}: suscripción exitosa`);
                    })
                    .error((e) => {
                        paint(el, 'ERROR', 'text-red-500');
                        log(`${label}: ERROR de suscripción -> ${JSON.stringify(e)}`);
                    });
            } else {
                paint(el, 'registrado', 'text-sky-400');
                log(`${label}: es público, registrado`);
            }
        };

        if (! window.Echo) {
            paint(socketEl, 'Echo no cargó', 'text-red-500');
            log('Error: window.Echo no existe.');
        } else {
            const cfg = window.__BROADCAST__ ?? {};
            urlEl.textContent = `${cfg.scheme}://${cfg.host}:${cfg.port}`;

            // Vinculamos eventos del conector
            const conn = window.Echo.connector.pusher.connection;

            conn.bind('connected', () => {
                paint(socketEl, 'conectado', 'text-emerald-400');
                log('socket WebSocket abierto');

                track(publicEl, 'publico', window.Echo.channel('tunnel-check-public'));
                track(privateEl, 'privado', window.Echo.private('tunnel-check'));
            });

            conn.bind('disconnected', () => {
                paint(socketEl, 'desconectado', 'text-red-500');
            });
        }

        document.querySelectorAll('[data-ping]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                await fetch(btn.dataset.ping, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json' },
                });
            });
        });
    </script>
</body>
</html>