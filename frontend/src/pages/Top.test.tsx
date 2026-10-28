import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import Top from './Top'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

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

  test('TC-7-02: 応答が返るまでは loading... を出す', () => {
    mockApiHandler('get', '/hello', () => new Promise<Response>(() => {}))

    render(<Top />)

    expect(screen.getByRole('heading', { name: 'loading...' })).toBeInTheDocument()
  })

  test('TC-7-03: API がエラーを返したら HTTP ステータスと本文を出す', async () => {
    // 500 の本文はスキーマに無いので、型を当てずに返す
    mockApiHandler('get', '/hello', () => jsonResponse({ message: 'Server Error' }, 500))

    render(<Top />)

    expect(
      await screen.findByRole('heading', { name: 'error: HTTP 500 {"message":"Server Error"}' }),
    ).toBeInTheDocument()
  })

  test('TC-7-04: 通信に失敗したら例外の内容を出す', async () => {
    mockApiHandler('get', '/hello', () => {
      throw new TypeError('Failed to fetch')
    })

    render(<Top />)

    expect(await screen.findByRole('heading', { name: 'error: TypeError: Failed to fetch' })).toBeInTheDocument()
  })
})
