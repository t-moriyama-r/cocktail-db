import { expect, test } from '@playwright/test'

// スマホ幅(375px)と PC 幅で、中身のまだ無いページに横スクロールが出ないこと、土台のスタイルが効いていることを確かめる
const viewports = [
  { label: 'スマホ幅', width: 375, height: 667 },
  { label: 'PC 幅', width: 1280, height: 720 },
]

const paths = [
  { path: '/', heading: '宅飲みカクテルデータベース' },
  { path: '/nope', heading: 'ページが見つかりません' },
]

for (const { label, width, height } of viewports) {
  for (const { path, heading } of paths) {
    test(`TC-295-05: ${label}(${width}px)で ${path} を開くと、横スクロールが出ない`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height })
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)

      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth)

      // 受け入れ条件の証跡として、HTML のレポートにスクリーンショットを添える
      await testInfo.attach(`${label}-${width}px${path.replaceAll('/', '_')}.png`, {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      })
    })
  }
}

test('TC-295-06: ビルドした画面に変数と土台のスタイルが入っている', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')

  const style = await page.evaluate(() => {
    const root = getComputedStyle(document.documentElement)
    const body = getComputedStyle(document.body)
    return {
      colorText: root.getPropertyValue('--color-text').trim(),
      bodyMargin: body.margin,
      bodyColor: body.color,
      bodyFontSize: body.fontSize,
      boxSizing: getComputedStyle(document.querySelector('h1')!).boxSizing,
    }
  })
  expect(style).toEqual({
    colorText: '#212121',
    bodyMargin: '0px',
    bodyColor: 'rgb(33, 33, 33)',
    bodyFontSize: '15px',
    boxSizing: 'border-box',
  })
})
