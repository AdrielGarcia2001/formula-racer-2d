import WebSocket from 'ws';
import crypto from 'node:crypto';

const httpBase = process.env.HTTP_BASE;
const wsBase = process.env.WS_BASE;
const channel = 'tunnel-check';
const waitMs = Number(process.env.WS_WAIT_MS || 20000);

const state = { cookies: '', events: 0, subscribed: false, socketId: null };
const log = (m) => console.log(`[check] ${m}`);

async function get(url) {
    const res = await fetch(url, { headers: { Cookie: state.cookies } });
    state.cookies = (res.headers.getSetCookie?.() ?? []).map((c) => c.split(';')[0]).join('; ');
    return res;
}

log(`1. cargando ${httpBase}/tunnel-check`);
const page = await get(`${httpBase}/tunnel-check`);
const html = await page.text();
const csrf = html.match(/name="csrf-token" content="([^"]+)"/)?.[1];
const player = html.match(/<span class="text-sky-300">([^<]*)<\/span>/)?.[1];
log(`   http=${page.status}  jugador=${player}`);

const key = process.env.REVERB_APP_KEY;
const wsUrl = wsBase.replace(/^http/, 'ws') + `/app/${key}?protocol=7&client=ws-check&version=8.6.0`;

log(`2. conectando el socket a ${wsUrl}`);
const ws = new WebSocket(wsUrl, { handshakeTimeout: 10000 });

ws.on('error', (e) => log(`   ERROR socket: ${e.message}`));
ws.on('unexpected-response', (_q, r) => log(`   ERROR handshake: HTTP ${r.statusCode}`));

ws.on('message', async (raw) => {
    let frame;
    try { frame = JSON.parse(raw.toString()); } catch { return; }
    const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data;

    if (frame.event === 'pusher:connection_established') {
        state.socketId = data.socket_id;
        log(`   socket_id=${state.socketId}`);

        // Orden real del navegador: socket abierto -> pedir autorizacion -> suscribirse.
        log(`3. pidiendo autorizacion para private-${channel}`);
        const authRes = await fetch(
            `${httpBase}/broadcasting/auth?channel_name=private-${channel}&socket_id=${encodeURIComponent(state.socketId)}`,
            { method: 'POST', headers: { Cookie: state.cookies, 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } },
        );
        const body = await authRes.text();
        log(`   auth http=${authRes.status}`);
        if (authRes.status !== 200) {
            log(`   cuerpo: ${body.slice(0, 200)}`);
            console.log('RESULTADO: FALLA (no se pudo autorizar)');
            process.exit(1);
        }
        ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: `private-${channel}`, auth: JSON.parse(body).auth } }));
        return;
    }

    if (frame.event === 'pusher_internal:subscription_succeeded') {
        state.subscribed = true;
        log('   suscripcion CONFIRMADA');
    }
    if (frame.event === 'pusher:error') {
        log(`   ERROR pusher: ${JSON.stringify(data)}`);
    }
    if (String(frame.event).startsWith('tunnel.')) {
        state.events += 1;
        log(`   EVENTO #${state.events} ${frame.event} -> ${JSON.stringify(data)}`);
    }
});

setTimeout(() => ws.close(), waitMs);
ws.on('close', () => {
    console.log('---');
    console.log(`resumen: suscrito=${state.subscribed} eventos=${state.events}`);
    console.log(state.subscribed && state.events > 0 ? 'RESULTADO: OK' : 'RESULTADO: FALLA');
    process.exit(state.subscribed && state.events > 0 ? 0 : 1);
});