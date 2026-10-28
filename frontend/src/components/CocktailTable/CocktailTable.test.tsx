import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test, vi } from 'vitest'
import CocktailHitCount from './CocktailHitCount'
import CocktailTable from './CocktailTable'
import type { CocktailSort, CocktailTableRow, CocktailTableSortMode } from './CocktailTable.types'
import { buildCocktailRow as row } from './buildCocktailRow'

const LOCAL: CocktailTableSortMode = { mode: 'local' }

const renderTable = (cocktails: CocktailTableRow[], sort: CocktailTableSortMode = LOCAL) =>
  render(
    <MemoryRouter>
      <CocktailTable cocktails={cocktails} sort={sort} label="カクテル" />
    </MemoryRouter>,
  )

/** 体の行の、名前の列の文字を上から順に */
const names = () =>
  screen
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell')[1].textContent)

const clickHeader = (name: string) => fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }))

describe('CocktailTable の中身', () => {
  test('TC-298-04: 列は旧と同じ並びで、名前・英名はカクテルの詳細へ、ベースはベースのノードの詳細へリンクする', () => {
    renderTable([row({ id: 59, name: 'X.Y.Z', name_eng: 'X.Y.Z', base: { id: 7, name: 'ラム', name_eng: 'Rum' } })])

    expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toEqual([
      '製法',
      '名前',
      'Name',
      'ベース',
      '必須',
      '甘さ',
      '評価',
      '度数',
      '動画',
    ])
    const cells = within(screen.getAllByRole('row')[1]).getAllByRole('cell')
    expect(within(cells[1]).getByRole('link')).toHaveAttribute('href', '/detail/cocktail/59')
    expect(within(cells[2]).getByRole('link')).toHaveAttribute('href', '/detail/cocktail/59')
    expect(within(cells[3]).getByRole('link')).toHaveAttribute('href', '/category/7')
    expect(cells[3]).toHaveTextContent('ラム(Rum)')
  })

  test('TC-298-23: 英名が無いカクテルは、英名の列に中身が空のリンクを出さない', () => {
    renderTable([row({ id: 5, name: 'あ', name_eng: null })])

    const cells = within(screen.getAllByRole('row')[1]).getAllByRole('cell')
    expect(within(cells[2]).queryByRole('link')).toBeNull()
  })

  test('TC-298-05: 値は、必須の数・甘さの言葉・評価の ★・小数 1 桁の度数で出す', () => {
    renderTable([row({ recipe_count: 3, sweet_degree: -6, rate: 3, strength: 30 })])

    const cells = within(screen.getAllByRole('row')[1]).getAllByRole('cell')
    expect(cells.slice(4, 8).map((td) => td.textContent)).toEqual(['3', '中辛', '★★★', '30.0'])
  })

  test.each([
    [1, 'slash', 'ビルド'],
    [2, 'fill-drip', 'ステア'],
    [3, 'glass-martini-alt', 'シェイク'],
    [4, 'blender', 'ブレンド'],
    [5, 'glass-whiskey', 'プース・カフェ'],
    [6, 'mug-hot', 'ホット'],
  ])('TC-298-06: 技法 %s は %s のアイコンで、技法の名前(%s)を読み上げる', (id, icon, name) => {
    renderTable([row({ method: { id, name } })])

    expect(screen.getByRole('img', { name })).toHaveAttribute('data-icon', icon)
  })

  test('TC-298-07: 動画のあるカクテルは、YouTube の動画のページを別タブで開くアイコンを出し、行の色を変える印を付ける', () => {
    renderTable([row({ name: 'デビル', youtube: 'x9gaP5Q8lc4' })])

    const link = screen.getByRole('link', { name: 'デビルの動画(YouTube)' })
    expect(link).toHaveAttribute('href', 'https://www.youtube.com/watch?v=x9gaP5Q8lc4')
    expect(link).toHaveAttribute('target', '_blank')
    expect(screen.getAllByRole('row')[1].className).toMatch(/hasMovie/)
  })

  test('TC-298-08: 動画の無いカクテルは「-」を出し、行の色を変えない', () => {
    renderTable([row({ youtube: null })])

    const tr = screen.getAllByRole('row')[1]
    expect(within(tr).getAllByRole('cell')[8]).toHaveTextContent(/^-$/)
    expect(tr.className).toBe('')
  })

  test('TC-298-09: 0 件のときは見出しの行だけの表を出す', () => {
    renderTable([])

    expect(screen.getAllByRole('row')).toHaveLength(1)
    expect(screen.getAllByRole('columnheader')).toHaveLength(9)
  })
})

describe('CocktailTable の手元の並べ替え', () => {
  test('TC-298-10: 最初は渡した順のまま出す', () => {
    renderTable([row({ id: 1, name: 'う' }), row({ id: 2, name: 'あ' }), row({ id: 3, name: 'い' })])

    expect(names()).toEqual(['う', 'あ', 'い'])
  })

  test.each([
    ['度数', 'strength'],
    ['甘さ', 'sweet_degree'],
    ['評価', 'rate'],
    ['必須', 'recipe_count'],
  ] as const)('TC-298-11: %s は数で比べる(初めて押したときの向きは TC-298-12・13)', (header, field) => {
    renderTable([1, 3, 2].map((v) => row({ id: v, name: `c${v}`, [field]: v })))

    clickHeader(header)
    const first = names()
    clickHeader(header)

    expect([first, names()]).toEqual(
      field === 'recipe_count'
        ? [
            ['c1', 'c2', 'c3'],
            ['c3', 'c2', 'c1'],
          ]
        : [
            ['c3', 'c2', 'c1'],
            ['c1', 'c2', 'c3'],
          ],
    )
  })

  test('TC-298-12: 度数・甘さ・評価・動画は初めて押すと降順、もう一度押すと昇順になる', () => {
    renderTable([
      row({ id: 1, name: 'a', strength: 10, youtube: null }),
      row({ id: 2, name: 'b', strength: 20, youtube: 'v2', updated_at: '2020-01-01T00:00:00Z' }),
      row({ id: 3, name: 'c', strength: 30, youtube: 'v3', updated_at: '2021-01-01T00:00:00Z' }),
    ])

    clickHeader('動画')
    expect(names()).toEqual(['c', 'b', 'a'])
    expect(screen.getByRole('columnheader', { name: /^動画/ })).toHaveAttribute('aria-sort', 'descending')

    clickHeader('動画')
    expect(names()).toEqual(['b', 'c', 'a'])
    expect(screen.getByRole('columnheader', { name: /^動画/ })).toHaveAttribute('aria-sort', 'ascending')
  })

  test.each(['製法', '名前', 'Name', 'ベース', '必須'])('TC-298-13: %s は初めて押すと昇順になる', (header) => {
    renderTable([
      row({ id: 2, name: 'い', name_eng: 'b', base: { id: 2, name: 'い', name_eng: 'b' }, method: { id: 2, name: 'ステア' }, recipe_count: 2 }),
      row({ id: 1, name: 'あ', name_eng: 'a', base: { id: 1, name: 'あ', name_eng: 'a' }, method: { id: 1, name: 'ビルド' }, recipe_count: 1 }),
    ])

    clickHeader(header)

    expect(names()).toEqual(['あ', 'い'])
    expect(screen.getByRole('columnheader', { name: new RegExp(`^${header}`) })).toHaveAttribute('aria-sort', 'ascending')
  })

  test.each([
    ['甘さ', 'sweet_degree'],
    ['評価', 'rate'],
  ] as const)('TC-298-14: %s の無い行は、降順でも昇順でも最後に置く', (header, field) => {
    renderTable([row({ id: 1, name: 'none', [field]: null }), row({ id: 2, name: 'one', [field]: 1 }), row({ id: 3, name: 'two', [field]: 2 })])

    clickHeader(header)
    const desc = names()
    clickHeader(header)

    expect([desc, names()]).toEqual([
      ['two', 'one', 'none'],
      ['one', 'two', 'none'],
    ])
  })

  test('TC-298-15: 動画の無い行は降順でも昇順でも最後に置き、ある行どうしは更新日時で比べる', () => {
    renderTable([
      row({ id: 1, name: 'none', youtube: null, updated_at: '2030-01-01T00:00:00Z' }),
      row({ id: 2, name: 'old', youtube: 'v', updated_at: '2019-05-01T00:00:00Z' }),
      row({ id: 3, name: 'new', youtube: 'v', updated_at: '2022-05-01T00:00:00Z' }),
    ])

    clickHeader('動画')
    const desc = names()
    clickHeader('動画')

    expect([desc, names()]).toEqual([
      ['new', 'old', 'none'],
      ['old', 'new', 'none'],
    ])
  })

  test('TC-298-16: 名前は、カタカナとひらがな・全角と半角の英数字をそろえて比べる', () => {
    renderTable([
      row({ id: 1, name: 'ジン・トニック' }),
      row({ id: 2, name: 'しそ酒' }),
      row({ id: 3, name: 'Ｂ５２' }),
      row({ id: 4, name: 'A1' }),
    ])

    clickHeader('名前')

    // そろえると「A1」「B52」「しそ酒」「じん・とにっく」。英数字はかなより前
    expect(names()).toEqual(['A1', 'Ｂ５２', 'しそ酒', 'ジン・トニック'])
  })

  test('TC-298-17: ベースはベースの名前を、カタカナとひらがなをそろえて比べる', () => {
    renderTable([
      row({ id: 1, name: 'r', base: { id: 1, name: 'ラム', name_eng: 'Rum' } }),
      row({ id: 2, name: 'g', base: { id: 2, name: 'じん', name_eng: 'Gin' } }),
      row({ id: 3, name: 'w', base: { id: 3, name: 'ウイスキー', name_eng: 'Whisky' } }),
    ])

    clickHeader('ベース')

    expect(names()).toEqual(['w', 'g', 'r'])
  })

  test('TC-298-18: 英名は大文字と小文字をそろえて比べる', () => {
    renderTable([row({ id: 1, name: 'z', name_eng: 'b' }), row({ id: 2, name: 'y', name_eng: 'C' }), row({ id: 3, name: 'x', name_eng: 'A' })])

    clickHeader('Name')

    expect(names()).toEqual(['x', 'z', 'y'])
  })

  test('TC-298-19: 別の列を押すと、その列の初めの向きからやり直す', () => {
    renderTable([row({ id: 1, name: 'あ', strength: 10 }), row({ id: 2, name: 'い', strength: 20 })])

    clickHeader('名前')
    clickHeader('名前')
    expect(names()).toEqual(['い', 'あ'])

    clickHeader('度数')
    expect(names()).toEqual(['い', 'あ'])
    clickHeader('名前')
    expect(names()).toEqual(['あ', 'い'])
  })
})

describe('CocktailTable の外から並びを渡す形', () => {
  test('TC-298-20: 渡した順のまま出し、渡した並びの列に印を付け、押すと次の並びを返す', () => {
    const onSortChange = vi.fn<(next: CocktailSort) => void>()
    renderTable([row({ id: 1, name: 'い', strength: 10 }), row({ id: 2, name: 'あ', strength: 20 })], {
      mode: 'controlled',
      sort: { key: 'strength', isAsc: true },
      onSortChange,
    })

    expect(names()).toEqual(['い', 'あ'])
    expect(screen.getByRole('columnheader', { name: /^度数/ })).toHaveAttribute('aria-sort', 'ascending')

    clickHeader('度数')
    clickHeader('評価')

    expect(onSortChange.mock.calls.map(([next]) => next)).toEqual([
      { key: 'strength', isAsc: false },
      { key: 'rate', isAsc: false },
    ])
    // 並べ替えるのは外なので、押しても表の並びは変えない
    expect(names()).toEqual(['い', 'あ'])
  })
})

describe('CocktailHitCount', () => {
  test('TC-298-21: 見出し・件数と、並べ替えの案内を出す', () => {
    render(<CocktailHitCount heading="カクテル検索結果" count={12} />)

    expect(screen.getByText(/カクテル検索結果 12件ヒットしました。/)).toHaveTextContent(
      'カクテル検索結果 12件ヒットしました。列名をクリックするとソートします。',
    )
  })

  test('TC-298-22: 0 件でも件数を出す', () => {
    render(<CocktailHitCount heading="カクテル検索結果" count={0} />)

    expect(screen.getByText(/0件ヒットしました。/)).toBeInTheDocument()
  })
})
