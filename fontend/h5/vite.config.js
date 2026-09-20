import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import { pdfAssetsPlugin } from '../shared/pdfAssetsPlugin';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    base: './',
    plugins: [vue(), pdfAssetsPlugin(process.cwd())],
    resolve: { dedupe: ['vue', 'markdown-it', 'dompurify', '@lucide/vue', 'pdfjs-dist', 'docx-preview', 'exceljs', 'fflate', 'papaparse'] },
    server: {
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY || 'http://127.0.0.1:8787',
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: '../../server/public/h5',
      emptyOutDir: true,
      rollupOptions: {
        output: {
          assetFileNames: asset => asset.names?.some(name => name.endsWith('.mjs')) ? 'assets/[name]-[hash].js' : 'assets/[name]-[hash][extname]',
          manualChunks: {
            vue: ['vue'],
            vant: ['vant'],
            icons: ['@lucide/vue'],
          },
        },
      },
    },
  };
});
