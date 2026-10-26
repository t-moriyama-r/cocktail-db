import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import Top from './Top'
import type { paths } from '../api/schema'

type HelloResponse = paths['/hello']['get']['responses'][200]['content']['application/json']

// 本物の client は相対 URL(/api)で作られていて Node の fetch では解決できないため、
// 同じ型付きのクライアントを、絶対 URL と差し替えた fetch で作り直したものに置き換える
const fetchMock = vi.hoisted(() => vi.fn<(request: Request) => Promise<Response>>())

vi.mock('../api/client', async () => {
  const { default: createClient } = await import('openapi-fetch')
  return {
    api: createClient<paths>({ baseUrl: 'http://localhost/api', fetch: (request) => fetchMock(request) }),
  }
})

const jsonResponse = (body: unknown, status: number) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('Top', () => {
  beforeEach(() => {
    fetchMock.mockReset()
  })

  test('TC-7-01: GET /api/hello の message を見出しに出す', async () => {
    const body: HelloResponse = { message: 'Hello World from Laravel' }
    fetchMock.mockImplementation(async () => jsonResponse(body, 200))

    render(<Top />)

    expect(await screen.findByRole('heading', { name: 'Hello World from Laravel' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const request = fetchMock.mock.calls[0][0]
    expect(request.method).toBe('GET')
    expect(new URL(request.url).pathname).toBe('/api/hello')
  })

  test('TC-7-02: 応答が返るまでは loading... を出す', () => {
    fetchMock.mockImplementation(() => new Promise<Response>(() => {}))

    render(<Top />)

    expect(screen.getByRole('heading', { name: 'loading...' })).toBeInTheDocument()
  })

  test('TC-7-03: API がエラーを返したら HTTP ステータスと本文を出す', async () => {
    fetchMock.mockImplementation(async () => jsonResponse({ message: 'Server Error' }, 500))

    render(<Top />)

    expect(
      await screen.findByRole('heading', { name: 'error: HTTP 500 {"message":"Server Error"}' }),
    ).toBeInTheDocument()
  })

  test('TC-7-04: 通信に失敗したら例外の内容を出す', async () => {
    fetchMock.mockImplementation(async () => {
      throw new TypeError('Failed to fetch')
    })

    render(<Top />)

    expect(await screen.findByRole('heading', { name: 'error: TypeError: Failed to fetch' })).toBeInTheDocument()
  })
})
