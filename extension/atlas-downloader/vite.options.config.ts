import path from 'node:path';
import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

export default defineConfig({
  base: './',
  root: path.resolve(__dirname, 'src/options'),
  plugins: [vue(), tailwind()],
  publicDir: false,
  build: {
    outDir: path.resolve(__dirname, 'dist'),
    emptyOutDir: false,
    sourcemap: false,
    rollupOptions: {
      input: {
        options: path.resolve(__dirname, 'src/options/options.html'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name].js',
        assetFileNames: (assetInfo) => {
          // Keep CSS name stable for easy manifest/reference/debug.
          if (assetInfo.name === 'style.css') return 'options.css';
          return '[name][extname]';
        },
      },
    },
  },
});
