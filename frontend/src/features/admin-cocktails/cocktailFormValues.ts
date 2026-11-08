import type { components } from '../../api/schema'

type CocktailResource = components['schemas']['CocktailResource']

/**
 * 材料の 1 行の入力(#128)。並べ替え・削除の後も同じ行を指せるよう、行ごとに key を持つ(画面の部品の key・誤りの付け先)。
 * ノード・銘柄は、まだ選んでいなければ null。量は欄に出す文字のまま持つ。
 */
export type CocktailIngredientRow = {
  key: number
  categoryId: number | null
  brandId: number | null
  amount: string
  isOptional: boolean
}

/**
 * カクテルの基本情報のフォームの入力(#127)。欄に出す文字のまま持ち、送るときに multipart の値にする(toCocktailFormData)。
 * 選択の欄(技法・グラス・氷・評価・甘さ)も、選択肢の value の文字のまま持つ。空の選択肢は空の文字。
 * 数の欄(開始位置)も文字のまま持つ。空の欄を 0 にせず、空のまま送って API の検証に任せるため。
 */
export type CocktailFormValues = {
  /** ベース。まだ選んでいなければ null */
  baseCategoryId: number | null
  iceId: string
  glassId: string
  name: string
  nameEng: string
  /** 技法(旧の「製法」)。新規ではまだ選んでいないので空の文字 */
  methodId: string
  rate: string
  sweetDegree: string
  isMajor: boolean
  description: string
  /** 補足 */
  note: string
  /** YouTube の URL か動画 ID */
  youtube: string
  youtubeStartHour: string
  youtubeStartMinute: string
  youtubeStartSecond: string
  /** 画像のリンクの文字と URL(旧の「リンクテキスト」「リンクURL」) */
  imageTxt: string
  imageUrl: string
  /** 新しく上げる画像。選んでいなければ null(今の画像のまま) */
  image: File | null
  /** 材料の行。画面の並びのまま送る(並びがレシピの順になる) */
  ingredients: readonly CocktailIngredientRow[]
}

/** 材料の行の上限(docs/decisions/admin-screens.md の決まったこと 5。API の上限と同じ) */
export const MAX_INGREDIENT_ROWS = 20

/** 新規の画面で最初に出す材料の行の数(保存に要る件数。docs/decisions/admin-cocktails.md の決まったこと 10) */
const INITIAL_INGREDIENT_ROWS = 2

/** 何も入れていない材料の行 */
export const emptyIngredientRow = (key: number): CocktailIngredientRow => ({
  key,
  categoryId: null,
  brandId: null,
  amount: '',
  isOptional: false,
})

/**
 * 新規の画面の最初の入力。旧(CocktailEdit.vue の detailEditObj)と同じく、すべて空でメジャーは外す。
 * 材料は、保存に要る 2 行を空で出す(旧は 12 行の枠を空で出した)
 */
export const EMPTY_COCKTAIL_FORM: CocktailFormValues = {
  baseCategoryId: null,
  iceId: '',
  glassId: '',
  name: '',
  nameEng: '',
  methodId: '',
  rate: '',
  sweetDegree: '',
  isMajor: false,
  description: '',
  note: '',
  youtube: '',
  youtubeStartHour: '',
  youtubeStartMinute: '',
  youtubeStartSecond: '',
  imageTxt: '',
  imageUrl: '',
  image: null,
  ingredients: Array.from({ length: INITIAL_INGREDIENT_ROWS }, (_, index) => emptyIngredientRow(index)),
}

const text = (value: string | number | null): string => (value === null ? '' : String(value))

/** 編集で開いたカクテル(GET /api/admin/cocktails/{id})を、フォームの入力にする */
export function cocktailFormValuesOf(cocktail: CocktailResource): CocktailFormValues {
  return {
    baseCategoryId: cocktail.base_category_id,
    iceId: text(cocktail.ice_id),
    glassId: text(cocktail.glass_dictionary_id),
    name: cocktail.name,
    nameEng: cocktail.name_eng ?? '',
    methodId: text(cocktail.method_id),
    rate: text(cocktail.rate),
    sweetDegree: text(cocktail.sweet_degree),
    isMajor: cocktail.is_major,
    description: cocktail.description ?? '',
    note: cocktail.note ?? '',
    youtube: cocktail.youtube ?? '',
    youtubeStartHour: text(cocktail.youtube_start_hour),
    youtubeStartMinute: text(cocktail.youtube_start_minute),
    youtubeStartSecond: text(cocktail.youtube_start_second),
    imageTxt: cocktail.image_txt ?? '',
    imageUrl: cocktail.image_url ?? '',
    image: null,
    ingredients: cocktail.ingredients.map((ingredient, index) => ({
      key: index,
      categoryId: ingredient.category_id,
      brandId: ingredient.brand_id,
      amount: ingredient.amount,
      isOptional: ingredient.is_optional,
    })),
  }
}

/**
 * フォームの入力を、カクテルの新規・更新の API に送る multipart の本文にする。
 *
 * 画像を上げられるよう、画像を選んでいないときも multipart で送る(旧も FormData で送っていた。銘柄のフォームと同じ)。
 * 空の欄・空の選択肢は空の文字のまま送る(Laravel が null に読み、必須の欄なら 422 になる)。ベースを選んでいなければ送らない。
 * 下書きか公開かは押したボタンで決め、isDraft で受ける(docs/decisions/admin-screens.md の 1)。
 * 真偽値は API が読める "1"・"0" にする。材料は `ingredients[<行>][<項目>]` の形で、銘柄の無い行は銘柄を送らない。
 * 材料は、ノードを選んでいない行も飛ばさずに画面の並びのまま送る(API の誤りの「材料 N 行目」が画面の行と合うように)。
 * 行が 1 つも無いときは、空の ingredients を送る(更新で ingredients を送らないと、API は今の材料を残すため。#720)。
 * 更新は PHP が PUT の multipart を読まないので、POST に `_method=PUT` を付けて送る(SaveCocktailRequest の説明のとおり)。
 * 別名(aliases。#293)は送らない(送らなければ今の別名が残る)。
 */
export function toCocktailFormData(values: CocktailFormValues, method: 'POST' | 'PUT', isDraft: boolean): FormData {
  const form = new FormData()
  if (method === 'PUT') {
    form.append('_method', 'PUT')
  }
  if (values.baseCategoryId !== null) {
    form.append('base_category_id', String(values.baseCategoryId))
  }
  form.append('name', values.name)
  form.append('name_eng', values.nameEng)
  form.append('method_id', values.methodId)
  form.append('glass_dictionary_id', values.glassId)
  form.append('ice_id', values.iceId)
  form.append('rate', values.rate)
  form.append('sweet_degree', values.sweetDegree)
  form.append('is_major', values.isMajor ? '1' : '0')
  form.append('is_draft', isDraft ? '1' : '0')
  form.append('description', values.description)
  form.append('note', values.note)
  form.append('youtube', values.youtube)
  form.append('youtube_start_hour', values.youtubeStartHour)
  form.append('youtube_start_minute', values.youtubeStartMinute)
  form.append('youtube_start_second', values.youtubeStartSecond)
  form.append('image_txt', values.imageTxt)
  form.append('image_url', values.imageUrl)
  if (values.image !== null) {
    form.append('image', values.image)
  }
  if (values.ingredients.length === 0) {
    form.append('ingredients', '')
  }
  values.ingredients.forEach((row, index) => {
    const key = (field: string) => `ingredients[${index}][${field}]`
    form.append(key('category_id'), row.categoryId === null ? '' : String(row.categoryId))
    if (row.brandId !== null) {
      form.append(key('brand_id'), String(row.brandId))
    }
    form.append(key('amount'), row.amount)
    form.append(key('is_optional'), row.isOptional ? '1' : '0')
  })
  return form
}

/** 評価の選択肢(旧と同じく、空の選択肢の後に 5 から 1 の順) */
export const RATE_OPTIONS: readonly number[] = [5, 4, 3, 2, 1]

/** 甘さの選択肢(旧と同じく、空の選択肢の後に -10 から 10 までの 21 段) */
export const SWEET_DEGREE_OPTIONS: readonly number[] = Array.from({ length: 21 }, (_, index) => index - 10)

const googleSearchUrl = (query: string) => `https://www.google.co.jp/search?${new URLSearchParams({ q: query })}`

/** 英名の意味を外の検索サイトで調べるリンク(旧の「意味検索」。旧と同じく英名の後ろに「意味」を付けて検索する) */
export const meaningSearchUrl = (nameEng: string): string => googleSearchUrl(`${nameEng} 意味`)

/** カクテル言葉を外の検索サイトで調べるリンク(旧の「カクテル言葉検索」。旧と同じく名前の後ろに「カクテル言葉」を付けて検索する) */
export const cocktailWordSearchUrl = (name: string): string => googleSearchUrl(`${name} カクテル言葉`)
