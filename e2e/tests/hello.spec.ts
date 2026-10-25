import { expect, test } from '@playwright/test'

test('TC-8-01: トップを開くと、API から取った Hello World が出る', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
})
