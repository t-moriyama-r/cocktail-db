import { fireEvent, render, screen, within } from '@testing-library/react'
import { useState } from 'react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import type { components } from '../../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import CocktailSearch from './CocktailSearch'
import { defaultSearchFormValues, type SearchFormValues } from './searchForm'
import { SEARCH_STORAGE_KEYS } from './searchStorage'

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

/** 今の URL を出す(検索ボタンで移った先と、URL に入れた条件を確かめるため) */
function LocationProbe() {
  const { pathname, search } = useLocation()
  // output は status の役割を持ち、読み込み中の表示(status)と取り違えるので div にする
  return <div data-testid="location">{`${pathname}${search}`}</div>
}

/** フォームの値を持つ親(ヘッダーの代わり)。検索ボタンで移った先の URL を画面に出す */
function Harness({ searching = false }: { searching?: boolean }) {
  const [values, setValues] = useState<SearchFormValues>(defaultSearchFormValues)
  return (
    <MemoryRouter>
      <CocktailSearch values={values} onValuesChange={setValues} searching={searching} />
      <LocationProbe />
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
/** 検索ボタンで移った先の URL(#102 で、条件を URL のクエリに入れて結果の画面へ移るようにした) */
const submittedUrl = () => screen.getByTestId('location').textContent

// 保存(#104)は localStorage に残るので、テストごとに消す
beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

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
    // 技法を 0 個にしたことは、空の m で URL に残す
    expect(submittedUrl()).toBe('/result/Cocktails?n=11&m=')
  })

  test('TC-307-11: 検索ボタンで、選んだ検索方法・かんたん検索・技法・材料を渡す', async () => {
    await renderForm()

    fireEvent.click(screen.getByRole('radio', { name: 'AND検索' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'かんたん検索' }))
    fireEvent.click(methodBox('ホット'))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'トニックウォーター' }))
    fireEvent.click(searchButton())

    expect(submittedUrl()).toBe('/result/Cocktails?n=10,30&mode=and&easy=1&m=1,2,3,4,5')
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

    // 材料だけ選んで送ると、技法は 6 つちょうど(旧はリセットのたびに ID が重なって増えた)。すべての技法なので m は書かない
    fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
    fireEvent.click(searchButton())
    expect(submittedUrl()).toBe('/result/Cocktails?n=11')
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

  describe('保存・読み込み・リロード(#104)', () => {
    const button = (name: string) => screen.getByRole('button', { name })

    test('TC-104-01: 「保存」「読み込み」「リロード」「リセット」を、旧と同じ並びと説明の文言で出す', async () => {
      await renderForm()

      const group = within(screen.getByRole('group', { name: '読み込み' }))
      expect(group.getAllByRole('button').map((element) => element.textContent)).toEqual(['保存', '読み込み', 'リロード', 'リセット'])
      expect(button('保存')).toHaveAccessibleDescription('現在のチェック状況を保存(持っているお酒の状況を保存するのに使います)')
      expect(button('読み込み')).toHaveAccessibleDescription('保存したデータを読み込みます。')
      expect(button('リロード')).toHaveAccessibleDescription('前回の検索条件を読み込みます。')
      expect(button('リセット')).toHaveAccessibleDescription('初期状態に戻します')
    })

    test('TC-104-02: 保存が無いあいだは「読み込み」「リロード」を押せない。「保存」すると「読み込み」だけ押せる', async () => {
      await renderForm()
      expect(button('読み込み')).toBeDisabled()
      expect(button('リロード')).toBeDisabled()
      expect(button('保存')).toBeEnabled()

      fireEvent.click(button('保存'))
      expect(button('読み込み')).toBeEnabled()
      expect(button('リロード')).toBeDisabled()
    })

    test('TC-104-03: 「保存」した条件を、変えた後に「読み込み」で戻す', async () => {
      await renderForm()
      fireEvent.click(screen.getByRole('radio', { name: 'あるもの検索' }))
      fireEvent.click(screen.getByRole('checkbox', { name: 'かんたん検索' }))
      fireEvent.click(methodBox('ホット'))
      fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))
      fireEvent.click(screen.getByRole('checkbox', { name: 'トニックウォーター' }))
      fireEvent.click(button('保存'))

      fireEvent.click(button('リセット'))
      expect(screen.getByRole('checkbox', { name: 'ラム' })).not.toBeChecked()

      fireEvent.click(button('読み込み'))
      expect(screen.getByRole('radio', { name: 'あるもの検索' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'かんたん検索' })).toBeChecked()
      expect(methodBox('ホット')).not.toBeChecked()
      expect(methodBox('ビルド')).toBeChecked()
      // ラムの子(ホワイトラム・ダークラム)はすべて選んだので、ラムにチェックが入る(子は畳まれていて出ない)
      expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'トニックウォーター' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
    })

    test('TC-104-04: 「検索」で押した条件を残し、「リロード」で戻す。「保存」の値とは別に持つ', async () => {
      await renderForm()
      fireEvent.click(screen.getByRole('checkbox', { name: 'オレンジジュース' }))
      fireEvent.click(button('保存'))

      fireEvent.click(screen.getByRole('checkbox', { name: 'オレンジジュース' }))
      fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
      fireEvent.click(screen.getByRole('radio', { name: 'AND検索' }))
      fireEvent.click(searchButton())
      expect(button('リロード')).toBeEnabled()

      fireEvent.click(button('リセット'))
      fireEvent.click(button('リロード'))
      expect(screen.getByRole('radio', { name: 'AND検索' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'ジン' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'オレンジジュース' })).not.toBeChecked()

      fireEvent.click(button('読み込み'))
      expect(screen.getByRole('radio', { name: 'OR検索' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'オレンジジュース' })).toBeChecked()
    })

    test('TC-104-05: 保存があっても、開いたときは既定の状態のまま(自動で戻さない)。「読み込み」「リロード」は押せる', async () => {
      localStorage.setItem(SEARCH_STORAGE_KEYS.user, 'n=11&mode=and')
      localStorage.setItem(SEARCH_STORAGE_KEYS.instant, 'n=30&easy=1')
      await renderForm()

      expect(screen.getByRole('radio', { name: 'OR検索' })).toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'トニックウォーター' })).not.toBeChecked()
      expect(screen.getByRole('checkbox', { name: 'かんたん検索' })).not.toBeChecked()
      expect(searchButton()).toBeDisabled()
      expect(button('読み込み')).toBeEnabled()
      expect(button('リロード')).toBeEnabled()
    })

    test('TC-104-06: localStorage が例外を投げても、フォームを出して検索でき、「読み込み」「リロード」は押せないまま', async () => {
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('SecurityError')
      })
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('SecurityError')
      })
      await renderForm()

      fireEvent.click(screen.getByRole('checkbox', { name: 'ジン' }))
      fireEvent.click(button('保存'))
      expect(button('読み込み')).toBeDisabled()
      fireEvent.click(searchButton())
      expect(submittedUrl()).toBe('/result/Cocktails?n=11')
      expect(button('リロード')).toBeDisabled()
    })
  })
})
