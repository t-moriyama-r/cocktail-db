import type { CocktailTableRow } from './CocktailTable.types'

/**
 * テストと確認用の見本に使う、表の 1 行。全部の項目に値を入れた既定に、違うところだけを上書きして作る
 */
export function buildCocktailRow(overrides: Partial<CocktailTableRow> = {}): CocktailTableRow {
  return { ...DEFAULT_ROW, ...overrides }
}

const DEFAULT_ROW: CocktailTableRow = {
  id: 1,
  name: 'マティーニ',
  name_eng: 'Martini',
  is_major: true,
  base: { id: 10, name: 'ジン', name_eng: 'Gin' },
  method: { id: 2, name: 'ステア' },
  recipe_count: 2,
  strength: 34.5,
  sweet_degree: -5,
  rate: 4,
  youtube: null,
  updated_at: '2020-01-01T00:00:00.000000Z',
}
