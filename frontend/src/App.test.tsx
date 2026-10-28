import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, test } from 'vitest'
import App from './App'
import type { paths } from './api/schema'
import { mockApi } from './test/api'

type HelloResponse = paths['/hello']['get']['responses'][200]['content']['application/json']

const HELLO: HelloResponse = { message: 'Hello World from Laravel' }
const NOT_FOUND_HEADING = 'ページが見つかりません'

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const expectHeading = async (name: string) => {
  expect(await screen.findByRole('heading', { level: 1, name })).toBeInTheDocument()
}

describe('App のルーティング', () => {
  beforeEach(() => {
    // トップの画面が GET /api/hello を呼ぶ
    mockApi('get', '/hello', 200, HELLO)
  })

  test.each([
    ['TC-97-01', '/'],
    ['TC-97-02', '/index'],
  ])('%s: %s はトップの画面を出す', async (_id, path) => {
    renderAt(path)
    await expectHeading(HELLO.message)
  })

  test.each([
    ['TC-97-03', '/helpme', '更新ボランティア募集'],
    ['TC-97-04', '/result/FromNameResult/%E3%82%B8%E3%83%B3', '名前検索の結果'],
    ['TC-97-05', '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', '名前検索の結果'],
    ['TC-97-06', '/result/Cocktails', '条件検索の結果'],
    ['TC-97-07', '/category/1', 'カテゴリの詳細'],
    ['TC-97-08', '/detail/brand/1', '銘柄の詳細'],
    ['TC-97-09', '/detail/cocktail/1', 'カクテルの詳細'],
    ['TC-97-10', '/dictionary', '用語辞典'],
    ['TC-97-10', '/dictionary/', '用語辞典'],
    ['TC-97-11', '/dictionary/1', '用語の詳細'],
    ['TC-97-12', '/dictionary/abc', '用語の詳細'],
    ['TC-97-20', '/Detail/Cocktail/1', 'カクテルの詳細'],
  ])('%s: %s は「%s」の画面を出す', async (_id, path, heading) => {
    renderAt(path)
    await expectHeading(heading)
  })

  test.each([
    ['TC-97-13', '/detail/cocktail/abc'],
    ['TC-97-14', '/detail/cocktail/1a'],
    ['TC-97-14', '/detail/brand/abc'],
    ['TC-97-14', '/category/abc'],
    ['TC-97-15', '/nope'],
    ['TC-97-16', '/detail/category/1'],
    ['TC-97-16', '/detail/middle/1'],
    ['TC-97-17', '/admin'],
    ['TC-97-17', '/about'],
    ['TC-97-18', '/detail/cocktail/'],
    ['TC-97-18', '/result/FromNameResult/'],
  ])('%s: %s は 404 の画面を出す', async (_id, path) => {
    renderAt(path)
    await expectHeading(NOT_FOUND_HEADING)
  })

  test('TC-97-19: 404 の画面のリンクからトップへ戻れる', async () => {
    renderAt('/nope')

    fireEvent.click(await screen.findByRole('link', { name: 'トップへ戻る' }))

    await expectHeading(HELLO.message)
  })
})
