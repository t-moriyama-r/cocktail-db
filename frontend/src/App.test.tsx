import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, test } from 'vitest'
import App from './App'
import type { paths } from './api/schema'
import { buildCocktailDetail } from './features/cocktail-detail/buildCocktailDetail'
import { mockApi } from './test/api'

type DictionaryResponse = paths['/dictionaries/{dictionary}']['get']['responses'][200]['content']['application/json']
type CategoryDetailResponse = paths['/categories/{category}']['get']['responses'][200]['content']['application/json']

/** トップの画面の見出し(API の応答によらず出る) */
const TOP_HEADING = '宅飲みカクテルデータベース'
/** 用語の詳細の画面が出すもの(技法・グラスでない用語なので、カクテルの API は呼ばない) */
const DICTIONARY: DictionaryResponse = {
  data: {
    id: 1,
    name: 'ダッシュ',
    name_eng: 'Dash',
    category: { id: 6, name: '単位', name_eng: 'Unit' },
    kind: 'other',
    method_id: null,
    description: null,
    youtube: null,
    youtube_start_seconds: null,
    image: null,
    image_txt: null,
    image_url: null,
  },
}
const NOT_FOUND_HEADING = 'ページが見つかりません'
const ROOT_NODE = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const CATEGORY: CategoryDetailResponse = {
  data: {
    ...ROOT_NODE,
    description: null,
    is_major: true,
    is_like: false,
    youtube: null,
    youtube_created_at: null,
    youtube_start_seconds: null,
    image: null,
    image_txt: null,
    image_url: null,
    created_at: null,
    updated_at: null,
    path: [ROOT_NODE],
    children: [],
    famous_brand: null,
    brands: [],
  },
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const expectHeading = async (name: string) => {
  expect(await screen.findByRole('heading', { level: 1, name })).toBeInTheDocument()
}

describe('App のルーティング', () => {
  beforeEach(() => {
    // 用語辞典の一覧・詳細の画面が GET /api/dictionaries・/api/dictionaries/{id} を呼ぶ
    // 名前検索の結果の画面(#52)が GET /api/search を呼ぶ
    mockApi('get', '/search', 200, { cocktails: [], nodes: [], brands: [], ingredient_matches: [] })
    mockApi('get', '/dictionaries', 200, { data: [] })
    mockApi('get', '/dictionaries/{dictionary}', 200, DICTIONARY)
    // カテゴリの詳細の画面が GET /api/categories/{id} を呼ぶ(#105)
    mockApi('get', '/categories/{category}', 200, CATEGORY)
    // カクテルの詳細の画面が GET /api/cocktails/{id} を呼ぶ(見出しは「マティーニ(Martini)」)
    mockApi('get', '/cocktails/{cocktail}', 200, { data: buildCocktailDetail() })
    // 詳細の画面は、同じ名前・材料が似た・同じベースのカクテルの API も呼ぶ。どれも 0 件を返す。
    // 差し替えは `{cocktail}` を任意の 1 区切りに当て、後から登録したものを先に使うので、
    // /cocktails/random は上の登録より後に登録する(登録しないと詳細の本文が返り、一覧が配列でなく落ちる。#569)
    mockApi('get', '/cocktails/{cocktail}/same-name', 200, { data: [] })
    mockApi('get', '/cocktails/{cocktail}/related', 200, { data: [] })
    mockApi('get', '/cocktails/random', 200, { data: [] })
  })

  test.each([
    ['TC-97-01', '/'],
    ['TC-97-02', '/index'],
  ])('%s: %s はトップの画面を出す', async (_id, path) => {
    renderAt(path)
    await expectHeading(TOP_HEADING)
  })

  test.each([
    ['TC-97-03', '/helpme', '当DBの更新ボランティア募集について'],
    ['TC-97-04', '/result/FromNameResult/%E3%82%B8%E3%83%B3', '結果'],
    ['TC-97-05', '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', '結果'],
    ['TC-97-06', '/result/Cocktails', '条件検索の結果'],
    ['TC-97-07', '/category/1', '蒸留酒(Spirits)'],
    ['TC-97-08', '/detail/brand/1', '銘柄の詳細'],
    ['TC-97-09', '/detail/cocktail/1', 'マティーニ(Martini)'],
    ['TC-97-10', '/dictionary', '用語辞典'],
    ['TC-97-10', '/dictionary/', '用語辞典'],
    // 用語の詳細の題は用語の名前(#56)
    ['TC-97-11', '/dictionary/1', 'ダッシュ(Dash)'],
    ['TC-97-20', '/Detail/Cocktail/1', 'マティーニ(Martini)'],
  ])('%s: %s は「%s」の画面を出す', async (_id, path, heading) => {
    renderAt(path)
    await expectHeading(heading)
  })

  test.each([
    ['TC-97-13', '/detail/cocktail/abc'],
    ['TC-97-14', '/detail/cocktail/1a'],
    ['TC-97-14', '/detail/brand/abc'],
    ['TC-97-14', '/category/abc'],
    // #97 では用語の ID に数字の制限を付けず、用語の詳細の画面を出していた。#56 で、無い ID と同じく 404 の画面にした
    ['TC-97-12', '/dictionary/abc'],
    ['TC-97-15', '/nope'],
    ['TC-97-16', '/detail/category/1'],
    ['TC-97-16', '/detail/middle/1'],
    // #97 では /admin も 404 の画面を出していた。#119 で管理画面の枠を置いた(管理画面の表示は AdminLayout.test.tsx)
    ['TC-97-17', '/about'],
    ['TC-97-18', '/detail/cocktail/'],
    ['TC-97-18', '/result/FromNameResult/'],
  ])('%s: %s は 404 の画面を出す', async (_id, path) => {
    renderAt(path)
    await expectHeading(NOT_FOUND_HEADING)
  })

  test('TC-97-19: 404 の画面のリンクからトップへ戻れる', async () => {
    renderAt('/nope')

    fireEvent.click(await screen.findByRole('link', { name: 'トップへ戻る' }))

    await expectHeading(TOP_HEADING)
  })
})
