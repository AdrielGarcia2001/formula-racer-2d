import WebSocket from 'ws';
import crypto from 'node:crypto';

const url = process.env.WS_URL;
const key = process.env.REVERB_APP_KEY;
const secret = process.env.REVERB_APP_SECRET;
const channel = process.env.WS_CHANNEL || 'private-tunnel-check';
const waitMs = Number(process.env.WS_WAIT_MS || 15000);

if (!url || !key || !secret) {
    console.error('Falta WS_URL, REVERB_APP_KEY o REVERB_APP_SECRET');
    process.exit(2);
}

console.log(`[check] conectando a ${url}`);

const ws = new WebSocket(url, { handshakeTimeout: 10000 });

let socketId = null;
let subscribed = false;
let events = 0;
const failures = [];

const fail = (stage, detail) => {
    failures.push(`${stage}: ${detail}`);
    console.log(`[FALLO] ${stage}: ${detail}`);
};

ws.on('error', (err) => fail('conexion', err.message));

ws.on('unexpected-response', (_req, res) => {
    fail('handshake', `HTTP ${res.statusCode} ${res.statusMessage}`);
});

ws.on('close', (code, reason) => {
    console.log(`[check] cerrado code=${code} reason=${reason || '(vacio)'}`);
});

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
        socketId = data.socket_id;
        console.log(`[OK] conexion establecida socket_id=${socketId}`);

        const signature = crypto
            .createHmac('sha256', secret)
            .update(`${socketId}:${channel}`)
            .digest('hex');

        ws.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: { channel, auth: `${key}:${signature}` },
        }));
        console.log(`[check] suscribiendo a ${channel}`);
        return;
    }

    if (event === 'pusher:error') {
        fail('pusher:error', JSON.stringify(data));
        return;
    }

    if (event === 'pusher_internal:subscription_succeeded') {
        subscribed = true;
        console.log(`[OK] suscripcion confirmada a ${data?.channel || channel}`);
        return;
    }

    if (String(event).startsWith('tunnel.')) {
        events += 1;
        console.log(`[OK] EVENTO #${events} ${event} -> ${JSON.stringify(data)}`);
    }
});

setTimeout(() => {
    ws.close();
}, waitMs);

ws.on('close', () => {
    console.log('---');
    console.log(`resumen: conectado=${Boolean(socketId)} suscrito=${subscribed} eventos=${events}`);
    const ok = socketId && subscribed && events > 0;
    console.log(ok ? 'RESULTADO: OK' : `RESULTADO: FALLA ${JSON.stringify(failures)}`);
    process.exit(ok ? 0 : 1);
});