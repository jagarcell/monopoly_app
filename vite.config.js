import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const configDirectory = dirname(fileURLToPath(import.meta.url));
const hotFile = resolve(configDirectory, 'public/hot');

function relativeHotFilePlugin(basePath) {
    return {
        name: 'relative-hot-file',
        configureServer(server) {
            server.httpServer?.once('listening', () => {
                mkdirSync(dirname(hotFile), { recursive: true });
                writeFileSync(hotFile, basePath.replace(/\/$/, ''));
            });
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const viteBase = env.VITE_DEV_BASE ?? '/vite-dev/';
    const hmrPath = env.VITE_HMR_PATH ?? '/vite-hmr';
    const allowedHosts = (env.VITE_ALLOWED_HOSTS ?? 'localhost,jagarcellhost.ddns.net')
        .split(',')
        .map((host) => host.trim())
        .filter(Boolean);

    return {
        base: mode === 'development' ? viteBase : undefined,
        server: {
            host: '0.0.0.0',
            port: Number(env.VITE_PORT ?? 5173),
            strictPort: true,
            allowedHosts,
            hmr: {
                protocol: 'wss',
                clientPort: Number(env.APP_SSL_PORT ?? 443),
                path: hmrPath,
            },
        },
        plugins: [
            laravel({
                input: 'resources/js/app.js',
                refresh: true,
            }),
            relativeHotFilePlugin(viteBase),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
