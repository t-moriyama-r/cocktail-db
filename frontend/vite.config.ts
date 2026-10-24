import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // 本番は Laravel(backend/public/spa)から配信し、HTML は Laravel の Blade がマニフェストを読んで組み立てる
  base: '/spa/',
  build: {
    outDir: '../backend/public/spa',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: { input: 'src/main.tsx' },
  },
  server: {
    // 本番と同じく /api を同一オリジンで呼べるように、開発時は Laravel に流す
    proxy: { '/api': 'http://127.0.0.1:8000' },
  },
})
