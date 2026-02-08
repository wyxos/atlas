import path from 'node:path';
import {defineConfig} from 'vite';

export default defineConfig({
  root: path.resolve(__dirname),
  publicDir: false,
  build: {
    outDir: path.resolve(__dirname, 'dist'),
    emptyOutDir: false,
    sourcemap: false,
    minify: 'esbuild',
    lib: {
      entry: path.resolve(__dirname, 'src/background/main.ts'),
      name: 'AtlasDownloaderBackground',
      formats: ['iife'],
      fileName: () => 'background.js',
    },
  },
});

