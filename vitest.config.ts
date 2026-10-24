import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';
import { existsSync } from 'fs';

// The components import the core's sources through '@/': the tests run inside the core
// (src/modules/X locally, modules/X in the Docker image), as the build does
const coreJs = [
    resolve(__dirname, '../../resources/js'),
    resolve(__dirname, '../resources/js'),
].find((dir) => existsSync(dir));

if (coreJs === undefined) {
    throw new Error('The Vue tests need the module inside the GuildForge core (modules/tournaments).');
}

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': coreJs,
            // The core's icons, as ES modules: Vite dedupes their Vue import, while the
            // CommonJS build would require the core's own Vue copy
            '@lucide/vue': resolve(coreJs, '../../node_modules/@lucide/vue/dist/esm/lucide-vue.mjs'),
        },
        // The core's sources and the module's would otherwise load a Vue copy each
        dedupe: ['vue', 'vue-i18n'],
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.ts'],
        setupFiles: ['tests/js/setup.ts'],
        restoreMocks: true,
        // Processed by Vite, so that dedupe reaches their Vue import
        server: { deps: { inline: ['@lucide/vue'] } },
    },
});
