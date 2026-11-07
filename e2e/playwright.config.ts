import { defineConfig, devices } from '@playwright/test'

// FE は事前にビルドして backend/public/spa に置き、Laravel から同じオリジンで配信したものを相手にする(本番と同じ形)
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  testDir: './tests',
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // 相手は php artisan serve 1 本なので、手元では worker を 1 本にする。既定(コア数の半分)で並べると要求が重なり、
  // page.goto のタイムアウトで大半が落ちる(#708)。CI は既定のまま(今の runner は 2 本で、213 件が 1.5 分で通っている)
  // 一時的に並べたいときは npm test -- --workers=<数> で上書きできる
  workers: process.env.CI ? undefined : 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL,
    // 失敗したときだけ trace とスクリーンショットを残し、CI のアーティファクトに上げる
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    cwd: '../backend',
    url: `${baseURL}/up`,
    // 手元では起動済みの php artisan serve があればそれを使う
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
