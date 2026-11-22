import type { CocktailDetailData, CocktailIngredient } from './CocktailDetail.types'

/**
 * テストに使うカクテルの詳細。全部の項目に値を入れた既定に、違うところだけを上書きして作る
 */
export function buildCocktailDetail(overrides: Partial<CocktailDetailData> = {}): CocktailDetailData {
  return { ...DEFAULT_DETAIL, ...overrides }
}

/** テストに使う材料の 1 つ。既定は銘柄の無い、いちばん上の段の下のノード(ジン / ドライジン) */
export function buildIngredient(overrides: Partial<CocktailIngredient> = {}): CocktailIngredient {
  return { ...DEFAULT_INGREDIENT, ...overrides }
}

const DEFAULT_INGREDIENT: CocktailIngredient = {
  position: 1,
  amount: '45ml',
  is_optional: false,
  path: [
    { id: 1, name: '蒸留酒', name_eng: 'Spirits' },
    { id: 10, name: 'ジン', name_eng: 'Gin' },
    { id: 11, name: 'ドライジン', name_eng: 'Dry Gin' },
  ],
  brand: null,
}

const DEFAULT_DETAIL: CocktailDetailData = {
  id: 59,
  name: 'マティーニ',
  name_eng: 'Martini',
  is_draft: false,
  is_major: true,
  base: { id: 10, name: 'ジン', name_eng: 'Gin' },
  method: { id: 2, name: 'ステア', name_eng: 'Stir', dictionary_id: 42 },
  glass: { id: 80, name: 'カクテルグラス', name_eng: 'Cocktail Glass' },
  ice: null,
  strength: 34.5,
  sweet_degree: -5,
  rate: 4,
  description: [{ type: 'text', text: 'カクテルの王様。' }],
  note: null,
  youtube: null,
  youtube_created_at: null,
  youtube_start_seconds: null,
  instagram: null,
  image: null,
  image_txt: null,
  image_url: null,
  created_at: '2020-01-01T00:00:00.000000Z',
  updated_at: '2020-01-01T00:00:00.000000Z',
  ingredients: [DEFAULT_INGREDIENT],
  aliases: [],
  same_recipe: [],
}
