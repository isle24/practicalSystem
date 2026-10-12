import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: { dedupe: ['vue', '@lucide/vue'] },
  build: { outDir: 'dist/shell' },
});
