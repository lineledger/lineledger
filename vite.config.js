import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        // Docker Compose sets VITE_HOST=0.0.0.0 so the published 5173 port
        // is reachable from the host. Unset for host-native `npm run dev`.
        ...(process.env.VITE_HOST ? { host: process.env.VITE_HOST } : {}),
        ...(process.env.VITE_HMR_HOST ? { hmr: { host: process.env.VITE_HMR_HOST } } : {}),
        ...(process.env.VITE_ORIGIN ? { origin: process.env.VITE_ORIGIN } : {}),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
