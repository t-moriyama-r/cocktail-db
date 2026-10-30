import { fireEvent, render, screen, within } from '@testing-library/react'
import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import CocktailSearch from './CocktailSearch'
import { defaultSearchFormValues, type SearchConditions, type SearchFormValues } from './searchForm'
import { buildSelectionTree, toSendIds } from './selection'

type SearchFormNode = components['schemas']['SearchFormNodeResource']
type SearchFormMethod = components['schemas']['SearchFormMethodResource']

const node = (id: number, name: string, children: SearchFormNode[] = [], root_role: SearchFormNode['root_role'] = null): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role,
  children,
})

/** 蒸留酒 > ラム > ホワイトラム・ダークラム、ジン / ソフトドリンク > トニックウォーター・オレンジジュース */
const categories: SearchFormNode[] = [
  node(1, '蒸留酒', [node(10, 'ラム', [node(100, 'ホワイトラム'), node(101, 'ダークラム')]), node(11, 'ジン')]),
  node(3, 'ソフトドリンク', [node(30, 'トニックウォーター'), node(31, 'オレンジジュース')], 'soft_drink'),
]

/** 旧と同じ 6 つの技法(ID の順) */
const methods: SearchFormMethod[] = [
  { id: 1, name: 'ビルド' },
  { id: 2, name: 'ステア' },
  { id: 3, name: 'シェイク' },
  { id: 4, name: 'ブレンド' },
  { id: 5, name: 'プース・カフェ' },
  { id: 6, name: 'ホット' },
]

const tree = buildSelectionTree(categories)

/** フォームの値を持つ親(ヘッダーの代わり)。検索ボタンで渡された条件を画面に出す */
function Harness({ searching = false }: { searching?: boolean }) {
  const [values, setValues] = useState<SearchFormValues>(defaultSearchFormValues)
  const [submitted, setSubmitted] = useState<SearchConditions | null>(null)
  return (
    <MemoryRouter>
      <CocktailSearch values={values} onValuesChange={setValues} onSubmit={setSubmitted} searching={searching} />
      {submitted !== null && (
        <output data-testid="submitted">
          {JSON.stringify({ ...submitted, selection: toSendIds(tree, submitted.selection) })}
        </output>
      )}
    </MemoryRouter>
  )
}

const renderForm = async (props: { searching?: boolean } = {}) => {
  mockApi('get', '/search-form', 200, { categories, methods })
  render(<Harness {...props} />)
  await screen.findByRole('heading', { name: 'カクテル検索' })
}

const searchButton = () => screen.getByRole('button', { name: '検索' })
const methodBox = (name: string) => within(screen.getByRole('group', { name: '製法' })).getByRole('checkbox', { name })
const submitted = () => JSON.parse(screen.getByTestId('submitted').textContent ?? 'null')

describe('CocktailSearchForm', () => {
  test('TC-307-06: 開くと既定値(OR 検索・かんたん検索なし・技法はすべて・材料なし)で、検索ボタンを押せず「材料は必須です。」を出す', async () => {
    await renderForm()

    expect(screen.getByRole('radio', { name: 'OR検索' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'AND検索' })).not.toBeChecked()
    expect(screen.getByRole('radio', { name: 'あるもの検索' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'かんたん検索' })).not.toBeChecked()
    for (const method of methods) {
      expect(methodBox(method.name)).toBeChecked()
    }
    expect(screen.getByRole('checkbox', { name: 'ラム' })).not.toBeChecked()
    expect(searchButton()).toBeDisabled()
    expect(searchButton()).toHaveAccessibleDescription('材料は必須です。')
  })

  test('TC-307-07: 検索方法・かんたん検索の説明を旧と同じ文言で出す', async () => {
    await renderForm()

    expect(screen.getByRole('radio', { name: 'OR検索' })).toHaveAccessibleDescription('チェックした「いずれか」が含まれているカクテルを検索')
    expect(screen.getByRole('radio', { name: 'AND検索' })).toHaveAccessibleDescription('チェックした「すべて」が含まれているカクテルを検索')
    expect(screen.getByRole('radio', { name: 'あるもの検索' })).toHaveAccessibleDescription(
      '「チェックが入ったものだけ」で作れるカクテルを検索("その他"は省略可能なので無視されます)',
    )
    expect(screen.getByRole('checkbox', { name: 'かんたん検索' })).toHaveAccessibleDescription(
      '材料が少なく(3つ以下)簡単に作れるレシピのみに絞ります',
    )
  })

  test('TC-307-08: 技法はアイコン付きで並べ、アイコンは旧と同じもの', async () => {
    await renderForm()

    const icons = within(screen.getByRole('group', { name: '製法' }))
      .getAllByRole('checkbox')
      .map((box) => box.closest('label')?.querySelector('svg')?.getAttribute('data-icon'))
    expect(icons).toEqual(['slash', 'fill-drip', 'glass-martini-alt', 'blender', 'glass-whiskey', 'mug-hot'])
  })

  test('TC-307-09: 材料を 1 個選ぶと押せ、「材料は必須です。」が消える。外して 0 個に戻すと、また押せない', async () => {
    await renderForm()

    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
    expect(searchButton()).toBeEnabled()
    expect(screen.queryByText('材料は必須です。')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
    expect(searchButton()).toBeDisabled()
    expect(screen.getByText('材料は必須です。')).toBeInTheDocument()
  })

  test('TC-307-10: 技法を 1 個だけにしても、0 個にしても(材料 1 個)押せ、技法の誤りは出さない', async () => {
    await renderForm()
    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))

    for (const method of methods.slice(1)) {
      fireEvent.click(methodBox(method.name))
    }
    expect(searchButton()).toBeEnabled()

    fireEvent.click(methodBox('ビルド'))
    expect(searchButton()).toBeEnabled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByText(/製法は必須/)).not.toBeInTheDocument()
    fireEvent.click(searchButton())
    expect(submitted().methodIds).toEqual([])
  })

  test('TC-307-11: 検索ボタンで、選んだ検索方法・かんたん検索・技法・材料を渡す', async () => {
    await renderForm()

    fireEvent.click(screen.getByRole('radio', { name: 'AND検索' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'かんたん検索' }))
    fireEvent.click(methodBox('ホット'))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'トニックウォーター' }))
    fireEvent.click(searchButton())

    expect(submitted()).toEqual({ mode: 'and', isEasy: true, methodIds: [1, 2, 3, 4, 5], selection: [10, 30] })
  })

  test('TC-307-12: 「リセット」で既定値に戻る。2 回押しても技法の ID は重ならない', async () => {
    await renderForm()
    fireEvent.click(screen.getByRole('radio', { name: 'あるもの検索' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'かんたん検索' }))
    fireEvent.click(methodBox('ステア'))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))

    fireEvent.click(screen.getByRole('button', { name: 'リセット' }))
    fireEvent.click(screen.getByRole('button', { name: 'リセット' }))

    expect(screen.getByRole('radio', { name: 'OR検索' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'かんたん検索' })).not.toBeChecked()
    for (const method of methods) {
      expect(methodBox(method.name)).toBeChecked()
    }
    expect(screen.getByRole('checkbox', { name: 'ラム' })).not.toBeChecked()
    expect(searchButton()).toBeDisabled()

    // 材料だけ選んで送ると、技法は 6 つちょうど(旧はリセットのたびに ID が重なって増えた)
    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
    fireEvent.click(searchButton())
    expect(submitted()).toEqual({ mode: 'or', isEasy: false, methodIds: [1, 2, 3, 4, 5, 6], selection: [11] })
  })

  test('TC-307-13: 検索中は、検索ボタンに読み込み中の表示を出す', async () => {
    await renderForm({ searching: true })
    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))

    const button = screen.getByRole('button', { name: '検索中' })
    expect(button).toHaveAttribute('aria-busy', 'true')
    expect(screen.queryByRole('button', { name: '検索' })).not.toBeInTheDocument()
  })

  test('TC-307-14: 部品の API を待つあいだは「読み込み中…」、失敗したらエラーを出し、もう一度読み込むとフォームを出す', async () => {
    let failing = true
    const body: { categories: SearchFormNode[]; methods: SearchFormMethod[] } = { categories, methods }
    // 500 の本文はスキーマに無いので型を当てず、200 の本文には型を当てる
    mockApiHandler('get', '/search-form', () => (failing ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse(body, 200)))
    render(<Harness />)

    expect(screen.getByRole('status')).toHaveTextContent('読み込み中…')
    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')

    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))
    expect(await screen.findByRole('heading', { name: 'カクテル検索' })).toBeInTheDocument()
  })
})
