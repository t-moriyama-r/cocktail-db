import { expect, test } from '@playwright/test'

// 更新ボランティア募集の画面(/helpme。#57)を、証跡を撮る 4 つの幅(docs/design/style.md)で開き、旧の文が出て横スクロールが出ないことを確かめる
const widths = [375, 768, 1024, 1440]

for (const width of widths) {
  test(`TC-57-06: ${width}px で /helpme を開くと、旧の文が出て横スクロールが出ない`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto('/helpme')

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('当DBの更新ボランティア募集について')
    const main = page.getByRole('main')
    await expect(main).toContainText('＜募集要項＞')
    await expect(main).toContainText('管理者ページのログインにはパスキーを用います。')
    await expect(main).not.toContainText('ログインにtwitterアカウントを用います')

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)

    await testInfo.attach(`helpme-${width}px.png`, {
      body: await page.screenshot({ fullPage: true }),
      contentType: 'image/png',
    })
  })
}
