import path from 'node:path';
import {defineConfig} from 'vite';
import tailwind from '@tailwindcss/vite';

export default defineConfig({
  root: path.resolve(__dirname),
  plugins: [tailwind()],
  publicDir: false,
  build: {
    outDir: path.resolve(__dirname, 'dist'),
    emptyOutDir: false,
    sourcemap: false,
    minify: 'esbuild',
    cssCodeSplit: false,
    lib: {
      entry: path.resolve(__dirname, 'src/content/main.ts'),
      name: 'AtlasDownloaderContent',
      formats: ['iife'],
      cssFileName: 'content',
      fileName: () => 'content.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: (assetInfo) => {
          // Vite emits extracted CSS as style.css in lib mode; keep stable name.
          if (assetInfo.name === 'style.css') return 'content.css';
          return '[name][extname]';
        },
      },
    },
  },
});
