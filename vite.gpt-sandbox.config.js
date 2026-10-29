import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
    resolve: { alias: { '@': path.resolve(import.meta.dirname, 'resources/js') } },
    plugins: [
        vue(),
        laravel({
            input: ['resources/js/gpt-sandbox/index.js'],
            buildDirectory: 'build/gpt-sandbox',
            hotFile: 'public/gpt-sandbox.hot',
            refresh: false,
        }),
    ],
    build: { emptyOutDir: true },
});
