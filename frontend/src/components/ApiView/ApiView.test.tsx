import { act, fireEvent, render, screen } from '@testing-library/react'
import { useCallback, type ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'
import { useApi } from '../../api/useApi'
import { jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import ApiView from './ApiView'

type HelloResponse = paths['/hello']['get']['responses'][200]['content']['application/json']
type SameNameResponse = paths['/cocktails/{cocktail}/same-name']['get']['responses'][200]['content']['application/json']

const HELLO: HelloResponse = { message: 'Hello World from Laravel' }
const LOADING = '読み込み中…'
const RETRY = 'もう一度読み込む'
const NETWORK_ERROR = '通信に失敗しました。接続を確かめて、もう一度お試しください。'
const httpErrorMessage = (status: number) =>
  `データを読み込めませんでした(エラー ${status})。時間をおいて、もう一度お試しください。`

const fetchHello = () => api.GET('/hello')

/** 共通の処理を使う画面の見本。GET /api/hello の message を見出しに出す */
function HelloScreen() {
  const { state, retry } = useApi(fetchHello)
  return (
    <ApiView state={state} onRetry={retry}>
      {(data) => (
        <main>
          <h1>{data.message}</h1>
        </main>
      )}
    </ApiView>
  )
}

/** URL の ID で API を呼ぶ画面の見本(詳細の画面と同じ形)。同じ名前のカクテルの件数を出す */
function SameNameScreen({ id }: { id: number }) {
  const fetchSameName = useCallback(
    () => api.GET('/cocktails/{cocktail}/same-name', { params: { path: { cocktail: id } } }),
    [id],
  )
  const { state, retry } = useApi(fetchSameName)
  return (
    <ApiView state={state} onRetry={retry}>
      {(data) => (
        <main>
          <h1>{`同じ名前 ${data.data.length} 件`}</h1>
        </main>
      )}
    </ApiView>
  )
}

// 404 の画面がトップへの Link を持つので、ルーターの中で描く
const renderScreen = (screenElement: ReactElement) => render(<MemoryRouter>{screenElement}</MemoryRouter>)

/** 応答を、テストから返すまで止めておく */
const deferred = () => {
  let resolve: (response: Response) => void = () => {}
  const promise = new Promise<Response>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe('ApiView と useApi', () => {
  test('TC-98-01: 200 なら、成功したときの画面を出す', async () => {
    mockApi('get', '/hello', 200, HELLO)

    renderScreen(<HelloScreen />)

    expect(await screen.findByRole('heading', { level: 1, name: HELLO.message })).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  test('TC-98-02: 応答が返るまでは、読み込み中の表示を出す', () => {
    mockApiHandler('get', '/hello', () => new Promise<Response>(() => {}))

    renderScreen(<HelloScreen />)

    expect(screen.getByRole('status')).toHaveTextContent(LOADING)
    expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument()
  })

  test('TC-98-03: 404 なら、404 の画面を出す', async () => {
    // 404 の本文はスキーマに無いので、型を当てずに返す
    mockApiHandler('get', '/cocktails/{cocktail}/same-name', () => jsonResponse({ message: 'Not Found' }, 404))

    renderScreen(<SameNameScreen id={999} />)

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'トップへ戻る' })).toBeInTheDocument()
  })

  test('TC-98-04: 500 なら、エラーの表示ともう一度読み込むボタンを出す', async () => {
    mockApiHandler('get', '/hello', () => jsonResponse({ message: 'Server Error' }, 500))

    renderScreen(<HelloScreen />)

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(httpErrorMessage(500))
    expect(screen.getByRole('button', { name: RETRY })).toBeInTheDocument()
  })

  test('TC-98-05: 通信に失敗したら、通信の失敗の表示ともう一度読み込むボタンを出す', async () => {
    mockApiHandler('get', '/hello', () => {
      throw new TypeError('Failed to fetch')
    })

    renderScreen(<HelloScreen />)

    expect(await screen.findByRole('alert')).toHaveTextContent(NETWORK_ERROR)
    expect(screen.getByRole('button', { name: RETRY })).toBeInTheDocument()
  })

  test.each([
    ['TC-98-06', 403],
    ['TC-98-06', 405],
    ['TC-98-06', 422],
    ['TC-98-06', 503],
  ])('%s: 404 でないエラー(%i)は、404 の画面ではなくエラーの表示を出す', async (_id, status) => {
    mockApiHandler('get', '/hello', () => jsonResponse({ message: 'error' }, status))

    renderScreen(<HelloScreen />)

    expect(await screen.findByRole('alert')).toHaveTextContent(httpErrorMessage(status))
    expect(screen.queryByRole('heading', { name: 'ページが見つかりません' })).not.toBeInTheDocument()
  })

  test.each([
    ['TC-98-07', '500', () => jsonResponse({ message: 'Server Error' }, 500)],
    [
      'TC-98-08',
      '通信の失敗',
      () => {
        throw new TypeError('Failed to fetch')
      },
    ],
  ])('%s: %s の後に、もう一度読み込むで読み込み中に戻り、成功したら画面を出す', async (_id, _label, fail) => {
    let failing = true
    const retried = deferred()
    mockApiHandler('get', '/hello', () => (failing ? fail() : retried.promise))
    renderScreen(<HelloScreen />)

    const button = await screen.findByRole('button', { name: RETRY })
    failing = false
    fireEvent.click(button)

    expect(await screen.findByRole('status')).toHaveTextContent(LOADING)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    await act(async () => retried.resolve(jsonResponse(HELLO, 200)))

    expect(await screen.findByRole('heading', { level: 1, name: HELLO.message })).toBeInTheDocument()
  })

  test('TC-98-09: ID が変わったら、前の ID の応答が後から返っても今の ID の結果を出す', async () => {
    const first = deferred()
    const empty: SameNameResponse = { data: [] }
    mockApiHandler('get', '/cocktails/{cocktail}/same-name', (request) =>
      new URL(request.url).pathname.endsWith('/cocktails/1/same-name') ? first.promise : jsonResponse(empty, 200),
    )
    const { rerender } = renderScreen(<SameNameScreen id={1} />)
    expect(screen.getByRole('status')).toHaveTextContent(LOADING)

    rerender(
      <MemoryRouter>
        <SameNameScreen id={2} />
      </MemoryRouter>,
    )
    expect(await screen.findByRole('heading', { level: 1, name: '同じ名前 0 件' })).toBeInTheDocument()

    await act(async () => first.resolve(jsonResponse({ message: 'Server Error' }, 500)))

    expect(screen.getByRole('heading', { level: 1, name: '同じ名前 0 件' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
