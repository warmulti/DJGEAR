import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  base: './',
  build: {
    outDir: resolve(__dirname, 'assets/dist'),
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'assets/src/scripts/main.ts'),
        woocommerce: resolve(__dirname, 'assets/src/styles/woocommerce-entry.css'),
      },
    },
  },
});
