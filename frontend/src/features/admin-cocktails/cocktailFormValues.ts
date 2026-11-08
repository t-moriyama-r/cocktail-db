import type { components } from '../../api/schema'

type CocktailResource = components['schemas']['CocktailResource']

/** 保存のときに送る形の材料の 1 行(GET /api/admin/cocktails/{id} の ingredients。#292) */
export type CocktailIngredientInput = CocktailResource['ingredients'][number]

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
  /**
   * 材料。この画面では編集せず(材料の行の編集は #128)、開いたカクテルの材料をそのまま送り返す。
   * 新規では空で、API が材料の件数(2 件以上)で 422 を返す
   */
  ingredients: readonly CocktailIngredientInput[]
}

/** 新規の画面の最初の入力。旧(CocktailEdit.vue の detailEditObj)と同じく、すべて空でメジャーは外す */
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
  ingredients: [],
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
    ingredients: cocktail.ingredients,
  }
}

/**
 * フォームの入力を、カクテルの新規・更新の API に送る multipart の本文にする。
 *
 * 画像を上げられるよう、画像を選んでいないときも multipart で送る(旧も FormData で送っていた。銘柄のフォームと同じ)。
 * 空の欄・空の選択肢は空の文字のまま送る(Laravel が null に読み、必須の欄なら 422 になる)。ベースを選んでいなければ送らない。
 * 下書きか公開かは押したボタンで決め、isDraft で受ける(docs/decisions/admin-screens.md の 1)。
 * 真偽値は API が読める "1"・"0" にする。材料は `ingredients[<行>][<項目>]` の形で、銘柄の無い行は銘柄を送らない。
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
  values.ingredients.forEach((ingredient, index) => {
    const key = (field: string) => `ingredients[${index}][${field}]`
    form.append(key('category_id'), String(ingredient.category_id))
    if (ingredient.brand_id !== null) {
      form.append(key('brand_id'), String(ingredient.brand_id))
    }
    form.append(key('amount'), ingredient.amount)
    form.append(key('is_optional'), ingredient.is_optional ? '1' : '0')
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
