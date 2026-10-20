import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: { dedupe: ['vue', '@lucide/vue'] },
  build: {
    outDir: 'dist/shell',
    rollupOptions: {
      input: {
        index: fileURLToPath(new URL('./index.html', import.meta.url)),
        agent: fileURLToPath(new URL('./agent.html', import.meta.url)),
      },
    },
  },
});
