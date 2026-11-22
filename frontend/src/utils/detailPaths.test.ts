import { expect, test } from 'vitest'
import { brandPath, categoryPath, cocktailPath, dictionaryPath } from './detailPaths'

test('TC-545-01: ノードの詳細の URL は /category/<ID>', () => {
  expect(categoryPath(12)).toBe('/category/12')
})

test('TC-545-02: カクテルの詳細の URL は /detail/cocktail/<ID>', () => {
  expect(cocktailPath(34)).toBe('/detail/cocktail/34')
})

test('TC-545-03: 銘柄の詳細の URL は /detail/brand/<ID>', () => {
  expect(brandPath(56)).toBe('/detail/brand/56')
})

test('TC-545-04: 用語の詳細の URL は /dictionary/<ID>', () => {
  expect(dictionaryPath(78)).toBe('/dictionary/78')
})
