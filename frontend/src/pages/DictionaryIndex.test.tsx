import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { paths } from '../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../test/api'
import DictionaryIndex from './DictionaryIndex'

type DictionaryIndexResponse = paths['/dictionaries']['get']['responses'][200]['content']['application/json']

const SITE_TITLE = '宅飲みカクテルデータベース(RC)'

/** 旧の分類(製法・グラス・単位)を ID の順に並べた一覧。単位は用語が 0 件 */
const INDEX: DictionaryIndexResponse = {
  data: [
    {
      id: 1,
      name: '製法',
      name_eng: 'Method',
      dictionaries: [
        { id: 12, name: 'シェイク', name_eng: 'Shake' },
        { id: 11, name: 'ステア', name_eng: 'Stir' },
      ],
    },
    {
      id: 2,
      name: 'グラス',
      name_eng: 'Glass',
      dictionaries: [{ id: 21, name: 'カクテル・グラス', name_eng: 'Cocktail glass' }],
    },
    { id: 6, name: '単位', name_eng: 'Unit', dictionaries: [] },
  ],
}

// 用語のリンクがあるので、ルーターの中で描く
const renderIndex = () =>
  render(
    <MemoryRouter>
      <DictionaryIndex />
    </MemoryRouter>,
  )

/** 分類の見出し(h2)を上から順に */
const categoryHeadings = () => screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)

/** 分類の見出しの下の用語のリンクを、上から順に「文字 → リンク先」で */
const termLinks = (categoryName: string) =>
  within(screen.getByRole('region', { name: categoryName }))
    .getAllByRole('link')
    .map((link) => `${link.textContent} → ${link.getAttribute('href')}`)

describe('DictionaryIndex', () => {
  test('TC-56-01: 分類ごとに見出し「名前(英名)」と、用語のリンク「名前(英名)」(→ 用語の詳細)を並べる', async () => {
    mockApi('get', '/dictionaries', 200, INDEX)

    renderIndex()

    expect(await screen.findByRole('heading', { level: 2, name: '製法(Method)' })).toBeInTheDocument()
    expect(termLinks('製法(Method)')).toEqual(['シェイク(Shake) → /dictionary/12', 'ステア(Stir) → /dictionary/11'])
    expect(termLinks('グラス(Glass)')).toEqual(['カクテル・グラス(Cocktail glass) → /dictionary/21'])
  })

  test('TC-56-02: 分類も用語も API の順のまま出す(画面で並べ替えない)', async () => {
    // API は分類を ID の順、用語を名前の順で返す(docs/decisions/dictionary.md)。画面の並べ替えで順が変わらないことを、
    // 名前の順と逆の並び(グラス → 製法、ステア → シェイク)を返して確かめる
    const reversed: DictionaryIndexResponse = {
      data: [
        { ...INDEX.data[1] },
        { ...INDEX.data[0], dictionaries: [...INDEX.data[0].dictionaries].reverse() },
      ],
    }
    mockApi('get', '/dictionaries', 200, reversed)

    renderIndex()

    await screen.findByRole('heading', { level: 2, name: '製法(Method)' })
    expect(categoryHeadings()).toEqual(['グラス(Glass)', '製法(Method)'])
    expect(termLinks('製法(Method)')).toEqual(['ステア(Stir) → /dictionary/11', 'シェイク(Shake) → /dictionary/12'])
  })

  test('TC-56-03: 用語が 0 件の分類は出さない', async () => {
    mockApi('get', '/dictionaries', 200, INDEX)

    renderIndex()

    await screen.findByRole('heading', { level: 2, name: '製法(Method)' })
    expect(categoryHeadings()).toEqual(['製法(Method)', 'グラス(Glass)'])
    expect(screen.queryByText('単位(Unit)')).not.toBeInTheDocument()
  })

  test('TC-56-04: ページのタイトルは「用語辞典 ｜ サイト名」で、読み上げ用の題「用語辞典」を置く', async () => {
    mockApi('get', '/dictionaries', 200, INDEX)

    renderIndex()

    expect(await screen.findByRole('heading', { level: 1, name: '用語辞典' })).toBeInTheDocument()
    expect(document.title).toBe(`用語辞典 ｜ ${SITE_TITLE}`)
  })

  test('TC-56-05: API がエラーを返したら共通のエラーの表示を出し、もう一度読み込むと一覧を出す', async () => {
    let failing = true
    // 500 の本文はスキーマに無いので型を当てず、200 の本文には型を当てる
    mockApiHandler('get', '/dictionaries', () =>
      failing ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse(INDEX, 200),
    )
    renderIndex()

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('heading', { level: 2, name: '製法(Method)' })).toBeInTheDocument()
  })
})
