import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import path from 'path'

export default defineConfig({
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
        hmr: {
            protocol: "ws",
            host: "localhost", // atau ganti dengan IP local kamu jika perlu
            port: 5173,
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    // server: {
    //     host: true,
    //     hmr: {
    //         host: "localhost", // atau ganti dengan IP local kamu jika perlu
    //     },
    // },

});
