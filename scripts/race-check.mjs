import WebSocket from 'ws';
import crypto from 'node:crypto';

const appId = process.env.REVERB_APP_ID;
const key = process.env.REVERB_APP_KEY;
const host = process.env.REVERB_HOST || '127.0.0.1';
const port = process.env.REVERB_PORT || '8080';
const scheme = process.env.REVERB_SCHEME || 'http';
const wsScheme = scheme === 'https' ? 'wss' : 'ws';
const url = process.env.WS_URL || `${wsScheme}://${host}:${port}/app/${key}?protocol=7&client=ws-check&version=8.6.0`;
const channel = process.env.WS_CHANNEL || 'race.1';
const waitMs = Number(process.env.WS_WAIT_MS || 8000);

if (!key) {
    console.error('Falta REVERB_APP_KEY');
    process.exit(2);
}

const ws = new WebSocket(url, { handshakeTimeout: 10000 });

let events = 0;
let last = null;

ws.on('error', (err) => console.error('[FALLO] conexion:', err.message));

ws.on('message', (raw) => {
    let frame;
    try {
        frame = JSON.parse(raw.toString());
    } catch {
        return;
    }

    const { event, data: rawData } = frame;
    const data = typeof rawData === 'string' ? JSON.parse(rawData) : rawData;

    if (event === 'pusher:connection_established') {
        const socketId = data.socket_id;
        console.log(`[OK] conexion establecida socket_id=${socketId}`);
        ws.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: { channel },
        }));
        console.log(`[check] suscribiendo a ${channel} (canal publico)`);
        return;
    }

    if (event === 'pusher_internal:subscription_succeeded') {
        console.log(`[OK] suscripcion confirmada a ${data?.channel || channel}`);
        return;
    }

    if (event === 'pusher:error') {
        console.error('[FALLO] pusher:error', JSON.stringify(data));
        return;
    }

    if (String(event).startsWith('race.')) {
        events += 1;
        last = data;
        if (events === 1) {
            console.log(`[OK] primer evento ${event}`);
            console.log('    payload:', JSON.stringify(data));
        }
    }
});

setTimeout(() => {
    ws.close();
}, waitMs);

ws.on('close', () => {
    console.log('---');
    console.log(`resumen: eventos=${events}`);
    if (last && last.c && last.c.length) {
        console.log('autos:', JSON.stringify(last.c));
        const [id, x8, y8, a255, speed, gear, mode, boost, laps, charges] = last.c[0];
        console.log(`auto ${id}: x=${(x8 / 8).toFixed(1)} y=${(y8 / 8).toFixed(1)} angulo=${(a255 * 360 / 256).toFixed(1)} vel=${speed} marcha=${gear} modo=${mode} boost=${boost} vuelta=${laps} kers=${charges}`);
    }
    const ok = events > 0;
    console.log(ok ? 'RESULTADO: OK' : 'RESULTADO: FALLA (sin eventos)');
    process.exit(ok ? 0 : 1);
});
