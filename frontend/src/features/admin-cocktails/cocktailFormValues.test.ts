import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import {
  cocktailFormValuesOf,
  cocktailWordSearchUrl,
  EMPTY_COCKTAIL_FORM,
  meaningSearchUrl,
  RATE_OPTIONS,
  SWEET_DEGREE_OPTIONS,
  toCocktailFormData,
} from './cocktailFormValues'

type CocktailResource = components['schemas']['CocktailResource']

const COCKTAIL: CocktailResource = {
  id: 1,
  base_category_id: 2,
  name: 'ジントニック',
  name_eng: 'Gin and Tonic',
  method_id: 3,
  glass_dictionary_id: null,
  ice_id: 4,
  strength: 12.5,
  rate: null,
  sweet_degree: -10,
  is_major: true,
  is_draft: false,
  description: '定番。',
  note: null,
  youtube: null,
  youtube_start_hour: 0,
  youtube_start_minute: null,
  youtube_start_second: 30,
  youtube_created_at: null,
  image: null,
  image_txt: null,
  image_url: null,
  ingredients: [
    { category_id: 5, brand_id: 6, amount: '45ml', is_optional: false },
    { category_id: 7, brand_id: null, amount: '適量', is_optional: true },
  ],
  aliases: ['G&T'],
  updated_at: null,
}

/** 送る項目と値を、入れた順に並べる(ファイルはファイル名にする) */
const entries = (form: FormData) => {
  const pairs: [string, string][] = []
  form.forEach((value, key) => {
    pairs.push([key, typeof value === 'string' ? value : value.name])
  })
  return pairs
}

describe('フォームの入力', () => {
  test('TC-127-01: 開いたカクテルの値を欄の文字にし、null は空の文字、0 と負の数は数のまま、材料はそのまま持つ', () => {
    expect(cocktailFormValuesOf(COCKTAIL)).toEqual({
      baseCategoryId: 2,
      iceId: '4',
      glassId: '',
      name: 'ジントニック',
      nameEng: 'Gin and Tonic',
      methodId: '3',
      rate: '',
      sweetDegree: '-10',
      isMajor: true,
      description: '定番。',
      note: '',
      youtube: '',
      youtubeStartHour: '0',
      youtubeStartMinute: '',
      youtubeStartSecond: '30',
      imageTxt: '',
      imageUrl: '',
      image: null,
      ingredients: COCKTAIL.ingredients,
      aliases: [{ key: 0, name: 'G&T' }],
    })
  })

  test('TC-127-02: 新規は _method を付けず、押したボタンを is_draft にし、材料を ingredients[行][項目] で送る(銘柄の無い行は銘柄を送らない)', () => {
    const values = { ...cocktailFormValuesOf(COCKTAIL), baseCategoryId: null }

    expect(entries(toCocktailFormData(values, 'POST', true))).toEqual([
      ['name', 'ジントニック'],
      ['name_eng', 'Gin and Tonic'],
      ['method_id', '3'],
      ['glass_dictionary_id', ''],
      ['ice_id', '4'],
      ['rate', ''],
      ['sweet_degree', '-10'],
      ['is_major', '1'],
      ['is_draft', '1'],
      ['description', '定番。'],
      ['note', ''],
      ['youtube', ''],
      ['youtube_start_hour', '0'],
      ['youtube_start_minute', ''],
      ['youtube_start_second', '30'],
      ['image_txt', ''],
      ['image_url', ''],
      ['ingredients[0][category_id]', '5'],
      ['ingredients[0][brand_id]', '6'],
      ['ingredients[0][amount]', '45ml'],
      ['ingredients[0][is_optional]', '0'],
      ['ingredients[1][category_id]', '7'],
      ['ingredients[1][amount]', '適量'],
      ['ingredients[1][is_optional]', '1'],
      ['aliases[0]', 'G&T'],
    ])
    expect(toCocktailFormData(values, 'POST', false).get('is_draft')).toBe('0')
  })

  test('TC-127-03: 更新は _method=PUT を付け、ベース・選んだ画像も送る', () => {
    const image = new File(['x'], 'gin.png', { type: 'image/png' })
    const form = toCocktailFormData({ ...EMPTY_COCKTAIL_FORM, baseCategoryId: 9, image }, 'PUT', false)

    expect(form.get('_method')).toBe('PUT')
    expect(form.get('base_category_id')).toBe('9')
    expect(form.get('is_major')).toBe('0')
    expect((form.get('image') as File).name).toBe('gin.png')
    expect(entries(form).some(([key]) => key.startsWith('ingredients'))).toBe(false)
  })
})

describe('別名', () => {
  test('TC-83-01: 開いたカクテルの別名を、登録した順のまま欄の行にする', () => {
    const values = cocktailFormValuesOf({ ...COCKTAIL, aliases: ['G&T', 'ジン・トニック'] })

    expect(values.aliases.map((alias) => alias.name)).toEqual(['G&T', 'ジン・トニック'])
    // 行の印は重ならない(行を消しても、残った行の欄を取り違えない)
    expect(new Set(values.aliases.map((alias) => alias.key)).size).toBe(2)
    expect(EMPTY_COCKTAIL_FORM.aliases).toEqual([])
  })

  test('TC-83-02: 別名は欄の並びのまま aliases[番号] で送り、文字は整えずに送る(空の行も送る)', () => {
    const values = {
      ...EMPTY_COCKTAIL_FORM,
      aliases: [
        { key: 3, name: 'ジン・トニック' },
        { key: 1, name: 'G&T' },
        { key: 5, name: '' },
      ],
    }

    const aliasEntries = entries(toCocktailFormData(values, 'PUT', true)).filter(([key]) => key.startsWith('aliases'))
    expect(aliasEntries).toEqual([
      ['aliases[0]', 'ジン・トニック'],
      ['aliases[1]', 'G&T'],
      ['aliases[2]', ''],
    ])
  })

  test('TC-83-03: 別名が 1 つも無ければ、全部消すために空の値の aliases を 1 つだけ送る', () => {
    const aliasEntries = entries(toCocktailFormData(EMPTY_COCKTAIL_FORM, 'PUT', true)).filter(([key]) => key.startsWith('aliases'))

    expect(aliasEntries).toEqual([['aliases', '']])
  })
})

describe('選択肢と検索のリンク', () => {
  test('TC-127-04: 評価は 5 から 1、甘さは -10 から 10 までの 21 段', () => {
    expect(RATE_OPTIONS).toEqual([5, 4, 3, 2, 1])
    expect(SWEET_DEGREE_OPTIONS).toHaveLength(21)
    expect(SWEET_DEGREE_OPTIONS[0]).toBe(-10)
    expect(SWEET_DEGREE_OPTIONS[20]).toBe(10)
  })

  test('TC-127-05: 意味検索は「英名 意味」、カクテル言葉検索は「名前 カクテル言葉」を外の検索サイトで調べる', () => {
    expect(new URL(meaningSearchUrl('Gin and Tonic')).searchParams.get('q')).toBe('Gin and Tonic 意味')
    expect(new URL(cocktailWordSearchUrl('ジントニック')).searchParams.get('q')).toBe('ジントニック カクテル言葉')
    expect(new URL(meaningSearchUrl('A&B')).origin).toBe('https://www.google.co.jp')
    expect(new URL(meaningSearchUrl('A&B')).searchParams.get('q')).toBe('A&B 意味')
  })
})
