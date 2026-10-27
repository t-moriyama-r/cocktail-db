/**
 * 見た目の案の試作に流し込む見本のデータ。API は呼ばず、画面の見た目を比べるためだけに使う。
 * 列や項目は旧の画面(トップ・カクテル詳細・条件検索の結果の表)に出ていたものに合わせている。
 */

export type SampleCocktail = {
  id: number
  name: string
  nameEng: string
  method: string
  base: string
  baseEng: string
  recipeCount: number
  sweet: string
  rate: number
  strength: number
  hasMovie: boolean
}

export type SampleRecipe = {
  material: string
  materialEng: string
  amount: string
  optional?: boolean
}

export type SampleCocktailDetail = SampleCocktail & {
  glass: string
  description: string
  note: string
  recipes: SampleRecipe[]
}

export const SAMPLE_COCKTAILS: SampleCocktail[] = [
  { id: 1, name: 'マティーニ', nameEng: 'Martini', method: 'ステア', base: 'ジン', baseEng: 'Gin', recipeCount: 2, sweet: '辛口', rate: 3, strength: 34.6, hasMovie: true },
  { id: 2, name: 'グラスホッパー', nameEng: 'Grasshopper', method: 'シェイク', base: 'リキュール', baseEng: 'Liqueur', recipeCount: 3, sweet: '甘口', rate: 2, strength: 13.3, hasMovie: true },
  { id: 3, name: 'ジン・トニック', nameEng: 'Gin and Tonic', method: 'ビルド', base: 'ジン', baseEng: 'Gin', recipeCount: 2, sweet: '普通', rate: 3, strength: 11.4, hasMovie: false },
  { id: 4, name: 'モスコミュール', nameEng: 'Moscow Mule', method: 'ビルド', base: 'ウォッカ', baseEng: 'Vodka', recipeCount: 3, sweet: '甘め', rate: 2, strength: 9.1, hasMovie: true },
  { id: 5, name: 'マルガリータ', nameEng: 'Margarita', method: 'シェイク', base: 'テキーラ', baseEng: 'Tequila', recipeCount: 3, sweet: '辛め', rate: 3, strength: 26.0, hasMovie: false },
  { id: 6, name: 'カシス・オレンジ', nameEng: 'Cassis and Orange', method: 'ビルド', base: 'リキュール', baseEng: 'Liqueur', recipeCount: 2, sweet: '甘口', rate: 0, strength: 5.0, hasMovie: false },
  { id: 7, name: 'アイリッシュ・コーヒー', nameEng: 'Irish Coffee', method: 'ホット', base: 'ウイスキー', baseEng: 'Whisky', recipeCount: 4, sweet: '甘め', rate: 1, strength: 10.2, hasMovie: true },
]

export const SAMPLE_DETAIL: SampleCocktailDetail = {
  ...SAMPLE_COCKTAILS[0],
  glass: 'カクテル・グラス',
  description:
    'カクテルの王様とも呼ばれる、ジンとドライ・ベルモットだけで作るシンプルなカクテル。比率を変えるとドライにも甘口にも寄せられ、作る人の好みがはっきり出る。',
  note: 'ジンもベルモットも冷やしておくと、氷が溶けすぎず水っぽくならない。',
  recipes: [
    { material: 'ジン', materialEng: 'Gin', amount: '45ml' },
    { material: 'ドライ・ベルモット', materialEng: 'Dry Vermouth', amount: '15ml' },
    { material: 'オレンジ・ビターズ', materialEng: 'Orange Bitters', amount: '1dash', optional: true },
    { material: 'オリーブ', materialEng: 'Olive', amount: '1個', optional: true },
  ],
}

/** 条件検索の結果の画面の見出しに出す、検索した条件の見本 */
export const SAMPLE_SEARCH_CONDITIONS = ['ベース: ジン・ウォッカ・リキュールほか', '甘さ: 指定なし', '度数: 0〜40%']
