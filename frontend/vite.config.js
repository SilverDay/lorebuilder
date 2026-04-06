import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: '../public/assets',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: './src/main.js',
    },
  },
  server: {
    // In dev, proxy all /api calls to the PHP backend
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})
