import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import BrandTable from './BrandTable'
import type { BrandTableNode, BrandTableRow } from './BrandTable.types'
import { affiliationNodes } from './affiliationNodes'
import { buildBrandRow as row } from './buildBrandRow'

const renderTable = (brands: BrandTableRow[]) =>
  render(
    <MemoryRouter>
      <BrandTable brands={brands} label="銘柄" />
    </MemoryRouter>,
  )

/** 体の行の、名前の列の文字を上から順に */
const names = () =>
  screen
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell')[0].textContent)

/** 1 行目の体の行のセル */
const firstRowCells = () => within(screen.getAllByRole('row')[1]).getAllByRole('cell')

const clickHeader = (name: string) => fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }))

const ROOT: BrandTableNode = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const RUM: BrandTableNode = { id: 7, name: 'ラム', name_eng: 'Rum' }
const MIDDLE: BrandTableNode = { id: 30, name: 'キューバのラム', name_eng: 'Cuban Rum' }
const WHITE_RUM: BrandTableNode = { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' }

describe('BrandTable の中身', () => {
  test('TC-299-01: 列は名前・Name・所属・度数・動画で、名前・英名は銘柄の詳細へリンクする', () => {
    renderTable([row({ id: 12, name: 'バカルディ', name_eng: 'Bacardi' })])

    expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['名前', 'Name', '所属', '度数', '動画'])
    const cells = firstRowCells()
    expect(within(cells[0]).getByRole('link')).toHaveAttribute('href', '/detail/brand/12')
    expect(within(cells[1]).getByRole('link')).toHaveAttribute('href', '/detail/brand/12')
  })

  test('TC-299-02: 所属が 3 段の銘柄は、いちばん上の段と所属するノードだけを、それぞれノードの詳細へのリンクで出す', () => {
    renderTable([row({ path: [ROOT, RUM, MIDDLE, WHITE_RUM] })])

    const cell = firstRowCells()[2]
    expect(cell).toHaveTextContent(/^ラム\(Rum\) \/ ホワイトラム\(White Rum\)$/)
    expect(within(cell).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['/category/7', '/category/70'])
  })

  test('TC-299-03: 所属が 1 段(いちばん上の段そのもの)の銘柄は、その段だけを出す', () => {
    renderTable([row({ path: [ROOT, RUM] })])

    const cell = firstRowCells()[2]
    expect(cell).toHaveTextContent(/^ラム\(Rum\)$/)
    expect(within(cell).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['/category/7'])
  })

  test('TC-299-04: 所属が 2 段の銘柄は、2 段とも出す', () => {
    renderTable([row({ path: [ROOT, RUM, WHITE_RUM] })])

    expect(firstRowCells()[2]).toHaveTextContent(/^ラム\(Rum\) \/ ホワイトラム\(White Rum\)$/)
  })

  test.each([
    [40, '40'],
    [37.5, '37.5'],
    [null, ''],
    [0, ''],
  ])('TC-299-05: 度数 %s は「%s」と出す(無いときは空)', (strength, text) => {
    renderTable([row({ strength })])

    expect(firstRowCells()[3].textContent).toBe(text)
  })

  test('TC-299-06: 動画のある銘柄は、YouTube の動画のページを別タブで開くアイコンを出し、行の色を変える印を付ける', () => {
    renderTable([row({ name: 'バカルディ', youtube: 'x9gaP5Q8lc4' })])

    const link = screen.getByRole('link', { name: 'バカルディの動画(YouTube)' })
    expect(link).toHaveAttribute('href', 'https://www.youtube.com/watch?v=x9gaP5Q8lc4')
    expect(link).toHaveAttribute('target', '_blank')
    expect(screen.getAllByRole('row')[1].className).toMatch(/hasMovie/)
  })

  test('TC-299-07: 動画の無い銘柄は「-」を出し、行の色を変えない', () => {
    renderTable([row({ youtube: null })])

    expect(firstRowCells()[4]).toHaveTextContent(/^-$/)
    expect(screen.getAllByRole('row')[1].className).toBe('')
  })

  test('TC-299-08: 0 件のときは見出しの行だけの表を出す', () => {
    renderTable([])

    expect(screen.getAllByRole('row')).toHaveLength(1)
    expect(screen.getAllByRole('columnheader')).toHaveLength(5)
  })

  test('TC-299-16: 英名が無い銘柄は、英名の列に中身が空のリンクを出さない', () => {
    renderTable([row({ name_eng: '' })])

    expect(within(firstRowCells()[1]).queryByRole('link')).toBeNull()
  })
})

describe('BrandTable の並べ替え', () => {
  test('TC-299-09: 最初は渡した順のまま出す', () => {
    renderTable([row({ id: 1, name: 'う' }), row({ id: 2, name: 'あ' }), row({ id: 3, name: 'い' })])

    expect(names()).toEqual(['う', 'あ', 'い'])
  })

  test.each(['名前', 'Name', '所属', '度数', '動画'])('TC-299-10: %s は初めて押すと昇順、もう一度押すと降順になる', (header) => {
    renderTable([
      row({ id: 2, name: 'い', name_eng: 'b', path: [ROOT, { id: 2, name: 'い', name_eng: 'b' }], strength: 20, youtube: 'v', updated_at: '2021-01-01T00:00:00Z' }),
      row({ id: 1, name: 'あ', name_eng: 'a', path: [ROOT, { id: 1, name: 'あ', name_eng: 'a' }], strength: 10, youtube: 'v', updated_at: '2020-01-01T00:00:00Z' }),
    ])

    clickHeader(header)
    expect(names()).toEqual(['あ', 'い'])
    expect(screen.getByRole('columnheader', { name: new RegExp(`^${header}`) })).toHaveAttribute('aria-sort', 'ascending')

    clickHeader(header)
    expect(names()).toEqual(['い', 'あ'])
    expect(screen.getByRole('columnheader', { name: new RegExp(`^${header}`) })).toHaveAttribute('aria-sort', 'descending')
  })

  test('TC-299-11: 名前は、カタカナとひらがな・全角と半角の英数字をそろえて比べる', () => {
    renderTable([row({ id: 1, name: 'ボンベイ' }), row({ id: 2, name: 'ほろよい' }), row({ id: 3, name: 'Ｚ２' }), row({ id: 4, name: 'A1' })])

    clickHeader('名前')

    // そろえると「A1」「Z2」「ほろよい」「ぼんべい」。英数字はかなより前
    expect(names()).toEqual(['A1', 'Ｚ２', 'ほろよい', 'ボンベイ'])
  })

  test('TC-299-12: 英名は大文字と小文字をそろえて比べる', () => {
    renderTable([row({ id: 1, name: 'z', name_eng: 'b' }), row({ id: 2, name: 'y', name_eng: 'C' }), row({ id: 3, name: 'x', name_eng: 'A' })])

    clickHeader('Name')

    expect(names()).toEqual(['x', 'z', 'y'])
  })

  test('TC-299-13: 所属は、いちばん上の段の名前をカタカナとひらがなをそろえて比べ、同じ段の銘柄は渡した順のまま', () => {
    const gin: BrandTableNode = { id: 8, name: 'じん', name_eng: 'Gin' }
    renderTable([
      row({ id: 1, name: 'r2', path: [ROOT, RUM, WHITE_RUM] }),
      row({ id: 2, name: 'g', path: [ROOT, gin] }),
      row({ id: 3, name: 'r1', path: [ROOT, RUM] }),
    ])

    clickHeader('所属')

    expect(names()).toEqual(['g', 'r2', 'r1'])
  })

  test('TC-299-14: 度数は数で比べ、度数の無い行は昇順でも降順でも最後に置く', () => {
    renderTable([row({ id: 1, name: 'none', strength: null }), row({ id: 2, name: 'forty', strength: 40 }), row({ id: 3, name: 'five', strength: 5 })])

    clickHeader('度数')
    const asc = names()
    clickHeader('度数')

    expect([asc, names()]).toEqual([
      ['five', 'forty', 'none'],
      ['forty', 'five', 'none'],
    ])
  })

  test('TC-299-15: 動画は、無い行を昇順でも降順でも最後に置き、ある行どうしは更新日時で比べる(所属では並べない)', () => {
    renderTable([
      row({ id: 1, name: 'none', youtube: null, path: [ROOT, { id: 2, name: 'あ', name_eng: 'a' }] }),
      row({ id: 2, name: 'new', youtube: 'v', updated_at: '2022-05-01T00:00:00Z', path: [ROOT, { id: 3, name: 'い', name_eng: 'i' }] }),
      row({ id: 3, name: 'old', youtube: 'v', updated_at: '2019-05-01T00:00:00Z', path: [ROOT, { id: 4, name: 'う', name_eng: 'u' }] }),
    ])

    clickHeader('動画')
    const asc = names()
    clickHeader('動画')

    expect([asc, names()]).toEqual([
      ['old', 'new', 'none'],
      ['new', 'old', 'none'],
    ])
  })

  test('TC-299-17: 別の列を押すと、その列の昇順からやり直す', () => {
    renderTable([row({ id: 1, name: 'あ', strength: 20 }), row({ id: 2, name: 'い', strength: 10 })])

    clickHeader('名前')
    clickHeader('名前')
    expect(names()).toEqual(['い', 'あ'])

    clickHeader('度数')
    expect(names()).toEqual(['い', 'あ'])
    clickHeader('名前')
    expect(names()).toEqual(['あ', 'い'])
  })
})

describe('affiliationNodes', () => {
  test.each([
    ['大元だけ', [ROOT], [ROOT]],
    ['空', [], []],
  ])('TC-299-18: パンくずが%sのときは、そのまま返す', (_, path, expected) => {
    expect(affiliationNodes(path)).toEqual(expected)
  })
})
