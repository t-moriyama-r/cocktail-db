import type { BrandTableRow } from './BrandTable.types'

/**
 * テストと確認用の見本に使う、表の 1 行。全部の項目に値を入れた既定に、違うところだけを上書きして作る
 */
export function buildBrandRow(overrides: Partial<BrandTableRow> = {}): BrandTableRow {
  return { ...DEFAULT_ROW, ...overrides }
}

const DEFAULT_ROW: BrandTableRow = {
  id: 1,
  name: 'バカルディ スペリオール',
  name_eng: 'Bacardi Superior',
  path: [
    { id: 1, name: '蒸留酒', name_eng: 'Spirits' },
    { id: 7, name: 'ラム', name_eng: 'Rum' },
    { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' },
  ],
  strength: 40,
  youtube: null,
  updated_at: '2020-01-01T00:00:00.000000Z',
}
