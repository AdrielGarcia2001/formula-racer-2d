import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// La config puede venir inyectada por la vista (window.__BROADCAST__) para no
// tener que recompilar cuando cambia la URL del tunel, con fallback a build-time.
const runtime = window.__BROADCAST__ ?? {};

const scheme = runtime.scheme ?? import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const host = runtime.host ?? import.meta.env.VITE_REVERB_HOST ?? 'localhost';
const port = runtime.port ?? import.meta.env.VITE_REVERB_PORT ?? 8080;
const secure = scheme === 'https';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: runtime.key ?? import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: secure ? 80 : port,
    wssPort: secure ? 443 : port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
});
