import react from '@vitejs/plugin-react'
// vitest/config の defineConfig は、vite の設定に test を足した型を持つ
import { defineConfig } from 'vitest/config'

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
    // 本番と同じく /api と画像(/storage。#585)を同一オリジンで取れるように、開発時は Laravel に流す
    proxy: { '/api': 'http://127.0.0.1:8000', '/storage': 'http://127.0.0.1:8000' },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    // 1 件のテストの上限(既定の 5 秒)を延ばす。画面を何度か移って待つテストは、負荷がかかると 5 秒を超えた(#663)。
    // findBy…・waitFor の待ち(src/test/setup.ts で 5 秒)を何回か重ねても収まる長さにする
    testTimeout: 30000,
  },
})
