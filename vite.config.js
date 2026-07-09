import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import path from 'path'

export default defineConfig(({ mode }) => {
    // Load env variables agar bisa dibaca di konfigurasi ini
    const env = loadEnv(mode, process.cwd(), '');

    // Deteksi apakah menggunakan NGROK (via VITE_HMR_HOST env)
    const hmrHost = env.VITE_HMR_HOST || 'localhost';
    const hmrPort = parseInt(env.VITE_HMR_PORT || '5173');
    const hmrClientPort = parseInt(env.VITE_HMR_CLIENT_PORT || hmrPort);
    const usingNgrok = !!env.VITE_HMR_HOST;

    return {
        plugins: [
            react(),
            laravel({
                input: ["resources/css/app.css", "resources/js/app.jsx"],
                refresh: true,
            }),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            // Izinkan semua origin agar tidak CORS block saat diakses dari NGROK
            cors: true,
            hmr: usingNgrok
                ? {
                    // Saat NGROK: gunakan wss (WebSocket Secure) ke host ngrok vite
                    protocol: 'wss',
                    host: hmrHost,
                    port: hmrClientPort,
                    clientPort: hmrClientPort,
                }
                : {
                    // Saat lokal biasa: pakai ws ke localhost
                    protocol: 'ws',
                    host: 'localhost',
                    port: 5173,
                },
        },
        resolve: {
            alias: {
                '@': path.resolve(__dirname, './resources/js'),
            },
        },
    };
});
