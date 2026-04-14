import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
|--------------------------------------------------------------------------
| Reverb / Echo setup
|--------------------------------------------------------------------------
|
| Laravel Echo listens for broadcast events from Reverb.
| When a new MQTT reading is saved, the backend emits an event.
| This JS receives it and updates the page instantly.
|
*/

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
});

/*
|--------------------------------------------------------------------------
| Listen on public "meters" channel
|--------------------------------------------------------------------------
|
| We match the backend:
| - channel: meters
| - event: meter.reading.updated
|
 */

window.Echo.channel('meters')
    .listen('.meter.reading.updated', (event) => {
        // Let pages opt into realtime updates without hard-coding DOM ids here.
        window.dispatchEvent(new CustomEvent('meter-reading-updated', {
            detail: event,
        }));
    });
