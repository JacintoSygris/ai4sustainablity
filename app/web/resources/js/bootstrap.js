import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './consent-mount';
import { getBrowserConsent } from '../../public/consent/core.mjs';
import { connectRealtime } from '../../public/consent/realtime.mjs';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
window.Echo = null;

const stopRealtime = connectRealtime({
    consent: getBrowserConsent(),
    available: () => Boolean(pusherKey && window.App?.userId && window.App?.characterization),
    create: () => {
        window.Pusher = Pusher;
        return new Echo({
            broadcaster: 'pusher',
            key: pusherKey,
            wsHost: import.meta.env.VITE_PUSHER_HOST ?? `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1'}.pusher.com`,
            wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            enableStats: false,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
            authEndpoint: '/broadcasting/auth',
            auth: { headers: { 'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' } },
        });
    },
    changed: (connection) => {
        window.Echo = connection;
        if (!connection) delete window.Pusher;
        window.dispatchEvent(new Event('airis:echo-changed'));
    },
});
if (import.meta.hot) import.meta.hot.dispose(stopRealtime);
