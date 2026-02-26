import path from 'node:path';
import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

export default defineConfig({
  root: path.resolve(__dirname),
  plugins: [vue(), tailwind()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    __DEV__: JSON.stringify(false),
    __VUE_OPTIONS_API__: JSON.stringify(true),
    __VUE_PROD_DEVTOOLS__: JSON.stringify(false),
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
  },
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
