import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    base: './',
    plugins: [vue()],
    resolve: { dedupe: ['vue', 'markdown-it', 'dompurify', '@lucide/vue'] },
    server: {
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY || 'http://127.0.0.1:8787',
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: '../../server/public/pc',
      emptyOutDir: true,
      rollupOptions: {
        output: {
          manualChunks: {
            vue: ['vue'],
            element: ['element-plus'],
            icons: ['@lucide/vue'],
          },
        },
      },
    },
  };
});
