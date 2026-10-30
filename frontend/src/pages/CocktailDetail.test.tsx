import { render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, test } from 'vitest'
import CocktailDetail from './CocktailDetail'
import type { CocktailDetailData } from '../features/cocktail-detail/CocktailDetail.types'
import { buildCocktailDetail as detail, buildIngredient as ingredient } from '../features/cocktail-detail/buildCocktailDetail'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

const SPIRITS = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const RUM = { id: 7, name: 'ラム', name_eng: 'Rum' }
const CUBAN_RUM = { id: 30, name: 'キューバのラム', name_eng: 'Cuban Rum' }
const WHITE_RUM = { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' }
const SOFT_DRINK = { id: 2, name: 'ソフトドリンク', name_eng: 'Soft Drink' }
const LIME = { id: 200, name: 'ライムジュース', name_eng: 'Lime Juice' }
const BACARDI = { id: 501, name: 'バカルディ', name_eng: 'Bacardi' }

/** /detail/cocktail/59 を開き、GET /api/cocktails/59 にこのカクテルを返す。見出しが出るまで待つ */
const openDetail = async (cocktail: CocktailDetailData) => {
  mockApi('get', '/cocktails/{cocktail}', 200, { data: cocktail })
  render(
    <MemoryRouter initialEntries={[`/detail/cocktail/${cocktail.id}`]}>
      <Routes>
        <Route path="/detail/cocktail/:id" element={<CocktailDetail />} />
      </Routes>
    </MemoryRouter>,
  )
  await screen.findByRole('heading', { level: 1 })
}

/** ベースなどの欄の、見出し(dt)の文字に対応する値(dd) */
const dataValue = (term: string) => {
  const dt = screen.getAllByRole('term').find((element) => element.textContent === term)
  if (!dt) {
    throw new Error(`「${term}」の欄が無い`)
  }
  return dt.nextElementSibling as HTMLElement
}

const ingredientTable = () => screen.getByRole('table', { name: '材料' })

/** 材料の表の体の行の、列ごとの文字 */
const ingredientRows = () =>
  within(ingredientTable())
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell').map((td) => td.textContent))

describe('カクテルの詳細の画面', () => {
  test('TC-108-01: GET /api/cocktails/{URL の ID} を呼び、見出しに「名前(英名)」を出す', async () => {
    await openDetail(detail({ id: 59, name: 'マティーニ', name_eng: 'Martini' }))

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/^マティーニ\(Martini\)$/)
    expect(apiRequests().map((request) => `${request.method} ${new URL(request.url).pathname}`)).toContain(
      'GET /api/cocktails/59',
    )
  })

  test('TC-108-02: 英名の無いカクテルの見出しは、旧と同じく「名前()」', async () => {
    await openDetail(detail({ name: '名無し', name_eng: null }))

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/^名無し\(\)$/)
  })

  test('TC-108-03: 欄の並びはベース・製法・甘さ・おすすめ度・度数で、ベースは「名前(英名)」でノードの詳細へリンクする', async () => {
    await openDetail(detail({ base: { id: 10, name: 'ジン', name_eng: 'Gin' } }))

    expect(screen.getAllByRole('term').map((dt) => dt.textContent)).toEqual([
      'ベース',
      '製法',
      '甘さ',
      'おすすめ度',
      '度数',
      'グラス',
    ])
    expect(within(dataValue('ベース')).getByRole('link', { name: 'ジン(Gin)' })).toHaveAttribute('href', '/category/10')
  })

  test('TC-108-04: 製法は技法のアイコンと名前を出し、名前は技法を説明する用語の詳細(dictionary_id)へリンクする', async () => {
    await openDetail(detail({ method: { id: 2, name: 'ステア', name_eng: 'Stir', dictionary_id: 42 } }))

    const value = dataValue('製法')
    expect(within(value).getByRole('img', { name: 'ステア' })).toBeInTheDocument()
    expect(within(value).getByRole('link', { name: 'ステア' })).toHaveAttribute('href', '/dictionary/42')
  })

  test('TC-108-05: 用語の無い技法(dictionary_id が null)は、名前をリンクにせずに出す', async () => {
    await openDetail(detail({ method: { id: 3, name: 'シェイク', name_eng: 'Shake', dictionary_id: null } }))

    const value = dataValue('製法')
    expect(value).toHaveTextContent('シェイク')
    expect(within(value).queryByRole('link')).not.toBeInTheDocument()
  })

  test.each([
    [-5, '中辛'],
    [null, '-'],
  ])('TC-108-06: 甘さ %s は「%s」(一覧の表と同じ文字)', async (sweet, label) => {
    await openDetail(detail({ sweet_degree: sweet }))

    expect(dataValue('甘さ')).toHaveTextContent(new RegExp(`^${label}$`))
  })

  test.each([
    ['null', null, '未評価'],
    ['0', 0, '未評価'],
    ['1', 1, '★'],
    ['5', 5, '★★★★★'],
  ])('TC-108-07: おすすめ度 %s は「%s」', async (_name, rate, label) => {
    await openDetail(detail({ rate }))

    expect(dataValue('おすすめ度')).toHaveTextContent(new RegExp(`^${label}$`))
  })

  test.each([
    [34.5, '34.5%'],
    [12, '12%'],
    [0, '0%'],
  ])('TC-108-08: 度数 %s は丸めずに「%s」', async (strength, label) => {
    await openDetail(detail({ strength }))

    expect(dataValue('度数')).toHaveTextContent(new RegExp(`^${label}$`))
  })

  test('TC-108-09: グラスは「氷の名前グラスの名前」で、グラスの用語の詳細へリンクする', async () => {
    await openDetail(
      detail({
        glass: { id: 81, name: 'ロックグラス', name_eng: 'Old Fashioned Glass' },
        ice: { id: 3, name: 'クラッシュドアイス', name_eng: 'Crushed Ice' },
      }),
    )

    expect(within(dataValue('グラス')).getByRole('link', { name: 'クラッシュドアイスロックグラス' })).toHaveAttribute(
      'href',
      '/dictionary/81',
    )
  })

  test('TC-108-10: 氷が無ければ、グラスの欄はグラスの名前だけ', async () => {
    await openDetail(detail({ glass: { id: 80, name: 'カクテルグラス', name_eng: 'Cocktail Glass' }, ice: null }))

    expect(within(dataValue('グラス')).getByRole('link')).toHaveTextContent(/^カクテルグラス$/)
  })

  test('TC-108-11: グラスが無ければ「グラス」の欄を出さない(氷があっても)', async () => {
    await openDetail(detail({ glass: null, ice: { id: 3, name: 'クラッシュドアイス', name_eng: null } }))

    expect(screen.getAllByRole('term').map((dt) => dt.textContent)).not.toContain('グラス')
  })

  test('TC-108-12: 材料の表の列は「材料」「Material」「分量」で、材料は API が返した順(position の順)に並ぶ', async () => {
    await openDetail(
      detail({
        ingredients: [
          ingredient({ position: 1, path: [SPIRITS, RUM, WHITE_RUM], amount: '45ml' }),
          ingredient({ position: 2, path: [SOFT_DRINK, LIME], amount: '15ml' }),
          ingredient({ position: 3, path: [SPIRITS, RUM], amount: '1tsp.' }),
        ],
      }),
    )

    expect(within(ingredientTable()).getAllByRole('columnheader').map((th) => th.textContent)).toEqual([
      '材料',
      'Material',
      '分量',
    ])
    expect(ingredientRows()).toEqual([
      ['ラム / ホワイトラム', 'Rum / White Rum', '45ml'],
      ['ライムジュース', 'Lime Juice', '15ml'],
      ['ラム', 'Rum', '1tsp.'],
    ])
  })

  test('TC-108-13: 材料のノードは、いちばん上の段と材料のノードだけを出し(間は省く)、それぞれノードの詳細へリンクする', async () => {
    await openDetail(detail({ ingredients: [ingredient({ path: [SPIRITS, RUM, CUBAN_RUM, WHITE_RUM] })] }))

    const [ja, en] = within(within(ingredientTable()).getAllByRole('row')[1]).getAllByRole('cell')
    expect(ja).toHaveTextContent(/^ラム \/ ホワイトラム$/)
    expect(within(ja).getAllByRole('link').map((a) => [a.textContent, a.getAttribute('href')])).toEqual([
      ['ラム', '/category/7'],
      ['ホワイトラム', '/category/70'],
    ])
    expect(within(en).getAllByRole('link').map((a) => [a.textContent, a.getAttribute('href')])).toEqual([
      ['Rum', '/category/7'],
      ['White Rum', '/category/70'],
    ])
  })

  test('TC-108-14: 銘柄のある材料は、ノードの後ろに「(銘柄)」を付け、銘柄は銘柄の詳細へリンクする。Material は英名で同じ形', async () => {
    await openDetail(detail({ ingredients: [ingredient({ path: [SPIRITS, RUM, WHITE_RUM], brand: BACARDI })] }))

    const [ja, en] = within(within(ingredientTable()).getAllByRole('row')[1]).getAllByRole('cell')
    expect(ja).toHaveTextContent(/^ラム \/ ホワイトラム \(バカルディ\)$/)
    expect(within(ja).getByRole('link', { name: 'バカルディ' })).toHaveAttribute('href', '/detail/brand/501')
    expect(en).toHaveTextContent(/^Rum \/ White Rum \(Bacardi\)$/)
    expect(within(en).getByRole('link', { name: 'Bacardi' })).toHaveAttribute('href', '/detail/brand/501')
  })

  test('TC-108-15: 任意の材料は「材料」の列の後ろに「(任意)」を付ける。「Material」の列には付けない', async () => {
    await openDetail(
      detail({
        ingredients: [
          ingredient({ position: 1, path: [SPIRITS, RUM], is_optional: false }),
          ingredient({ position: 2, path: [SOFT_DRINK, LIME], is_optional: true, amount: '適量' }),
        ],
      }),
    )

    expect(ingredientRows()).toEqual([
      ['ラム', 'Rum', '45ml'],
      ['ライムジュース(任意)', 'Lime Juice', '適量'],
    ])
  })

  test('TC-108-16: 補足(note)があると、鉛筆のアイコンと「Note」の下に補足を出す', async () => {
    await openDetail(detail({ note: [{ type: 'text', text: 'ステアは手早く。' }] }))

    const note = screen.getByRole('region', { name: 'Note' })
    expect(note).toHaveTextContent(/^Noteステアは手早く。$/)
    expect(note.querySelector('svg[data-icon="pencil-alt"]')).not.toBeNull()
  })

  test.each([
    ['null', null],
    ['空の並び', []],
  ])('TC-108-17: 補足が %s なら「Note」を出さない', async (_name, note) => {
    await openDetail(detail({ note }))

    expect(screen.queryByRole('region', { name: 'Note' })).not.toBeInTheDocument()
    expect(screen.queryByText('Note')).not.toBeInTheDocument()
  })

  test('TC-108-18: 説明文を出し、上に「§Description§」の区切りを置く(出すのは 960px 未満だけ。幅は E2E で確かめる)', async () => {
    await openDetail(detail({ description: [{ type: 'text', text: 'カクテルの王様。' }] }))

    expect(screen.getByText('カクテルの王様。')).toBeInTheDocument()
    expect(screen.getByText('§Description§')).toBeInTheDocument()
  })

  test('TC-108-19: YouTube の動画があれば、開始位置つきで埋め込む', async () => {
    await openDetail(detail({ youtube: 'abcdefghijk', youtube_start_seconds: 30 }))

    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/abcdefghijk?start=30')
  })

  test('TC-108-20: YouTube の動画が無ければ埋め込まない', async () => {
    await openDetail(detail({ youtube: null }))

    expect(screen.queryByTitle('YouTube の動画')).not.toBeInTheDocument()
  })

  test('TC-108-21: 無い ID・下書き(API が 404)は 404 の画面を出す', async () => {
    mockApiHandler('get', '/cocktails/{cocktail}', () => jsonResponse({ message: 'Not Found' }, 404))
    render(
      <MemoryRouter initialEntries={['/detail/cocktail/999']}>
        <Routes>
          <Route path="/detail/cocktail/:id" element={<CocktailDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
  })
})
