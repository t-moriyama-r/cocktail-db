import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import { checkIngredientRows } from './checkIngredientRows'
import type { CocktailIngredientRow } from './cocktailFormValues'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

const node = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

/** 蒸留酒(根) > ジン > ドライジン > ロンドンドライジン、蒸留酒 > ウォッカ、その他(根) > ミント */
const TREE: SearchFormNode[] = [
  node(1, '蒸留酒', [node(2, 'ジン', [node(3, 'ドライジン', [node(4, 'ロンドンドライジン')])]), node(5, 'ウォッカ')]),
  node(6, 'その他', [node(7, 'ミント')]),
]

let nextKey = 0
const row = (categoryId: number | null, brandId: number | null = null): CocktailIngredientRow => ({
  key: nextKey++,
  categoryId,
  brandId,
  amount: '30ml',
  isOptional: false,
})

describe('レシピの重複', () => {
  test('TC-128-01: 同じノードで、どちらも銘柄の無い行が 2 つあると「レシピが重複しています！」、同じ銘柄でも同じ', () => {
    expect(checkIngredientRows([row(5), row(7), row(5)], 5, TREE)).toEqual(['レシピが重複しています！'])
    expect(checkIngredientRows([row(3, 8), row(3, 8)], 3, TREE)).toEqual(['レシピが重複しています！'])
  })

  test('TC-128-02: 同じノードでも、銘柄が違う・片方だけに銘柄がある行は重複にしない', () => {
    expect(checkIngredientRows([row(3, 8), row(3, 9)], 3, TREE)).toEqual([])
    expect(checkIngredientRows([row(3, 8), row(3)], 3, TREE)).toEqual([])
  })

  test('TC-128-03: ノードを選んでいない行は数えない(空の行が 2 つあっても重複にしない)', () => {
    expect(checkIngredientRows([row(5), row(null), row(null, null)], 5, TREE)).toEqual([])
  })
})

describe('ベースが材料にあるか', () => {
  test('TC-128-04: ベースが、どれかの行のノードそのものか、その祖先なら止めない(深さ 3 の祖先も含む)', () => {
    expect(checkIngredientRows([row(7), row(5)], 5, TREE)).toEqual([])
    expect(checkIngredientRows([row(4), row(7)], 2, TREE)).toEqual([])
  })

  test('TC-128-05: ベースが、どの行のノードとも同じでなく祖先でもなければ「ベースが見つかりません！」(行のノードの配下のベース・別の枝のベース)', () => {
    expect(checkIngredientRows([row(2), row(7)], 3, TREE)).toEqual(['ベースが見つかりません！'])
    expect(checkIngredientRows([row(5), row(7)], 2, TREE)).toEqual(['ベースが見つかりません！'])
  })

  test('TC-128-06: ベースが空のとき、ノードのある行が 1 つも無いときは、ベースを確かめない', () => {
    expect(checkIngredientRows([row(5), row(7)], null, TREE)).toEqual([])
    expect(checkIngredientRows([row(null), row(null)], 5, TREE)).toEqual([])
    expect(checkIngredientRows([], 5, TREE)).toEqual([])
  })

  test('TC-128-07: 重複とベースの両方に当たれば、両方の文を重複・ベースの順に返す', () => {
    expect(checkIngredientRows([row(7), row(7)], 5, TREE)).toEqual(['レシピが重複しています！', 'ベースが見つかりません！'])
  })
})
