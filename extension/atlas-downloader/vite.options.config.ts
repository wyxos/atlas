import path from 'node:path';
import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

export default defineConfig({
  base: './',
  root: path.resolve(__dirname, 'src/options'),
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
