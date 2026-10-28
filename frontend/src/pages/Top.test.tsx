import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import Top from './Top'
import type { paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type HelloResponse = paths['/hello']['get']['responses'][200]['content']['application/json']

describe('Top', () => {
  test('TC-7-01: GET /api/hello の message を見出しに出す', async () => {
    mockApi('get', '/hello', 200, { message: 'Hello World from Laravel' })

    render(<Top />)

    expect(await screen.findByRole('heading', { name: 'Hello World from Laravel' })).toBeInTheDocument()
    // GET /api/hello を呼ぶこと(メソッドとパス)は API との約束なので確かめる。何回呼ぶかはデータの取り方の作りなので見ない
    expect(apiRequests().map((request) => `${request.method} ${new URL(request.url).pathname}`)).toContain(
      'GET /api/hello',
    )
  })

  // 読み込み中・エラーの出し分けは共通の部品(components/ApiView)が受け持ち、そちらのテスト(TC-98-01〜09)で確かめる。
  // ここではトップがその共通の処理を使っていることだけを確かめる(前の TC-7-02〜04 の「loading...」「error: HTTP …」の見出しは #98 でやめた)
  test('TC-98-10: API がエラーを返したら共通のエラーの表示を出し、もう一度読み込むとトップを出す', async () => {
    let failing = true
    const hello: HelloResponse = { message: 'Hello World from Laravel' }
    // 500 の本文はスキーマに無いので型を当てず、200 の本文には型を当てる
    mockApiHandler('get', '/hello', () =>
      failing ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse(hello, 200),
    )
    render(
      <MemoryRouter>
        <Top />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('heading', { name: 'Hello World from Laravel' })).toBeInTheDocument()
  })
})
