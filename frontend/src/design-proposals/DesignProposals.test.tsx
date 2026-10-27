import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import DesignProposals from './DesignProposals'

const SCREEN_HEADINGS = {
  top: '宅飲みカクテルデータベース',
  detail: 'マティーニ(Martini)',
  result: '条件検索の結果',
} as const

const proposalOf = (container: HTMLElement) => container.querySelector('[data-proposal]')?.getAttribute('data-proposal')

describe('見た目の案の試作', () => {
  test.each(
    (['a', 'b', 'c'] as const).flatMap((proposal) =>
      (['top', 'detail', 'result'] as const).map((screenId) => [proposal, screenId] as const),
    ),
  )('TC-294-01: 案 %s の %s の画面を出し、案の印を付ける', (proposal, screenId) => {
    const { container } = render(<DesignProposals search={`?proposal=${proposal}&screen=${screenId}`} />)

    expect(screen.getByRole('heading', { level: 1, name: SCREEN_HEADINGS[screenId] })).toBeInTheDocument()
    expect(proposalOf(container)).toBe(proposal)
  })

  test.each([
    ['指定なし', ''],
    ['知らない値', '?proposal=z&screen=nope'],
    ['大文字', '?proposal=A&screen=TOP'],
  ])('TC-294-02: 案・画面が%sなら、案 A のトップを出す', (_label, search) => {
    const { container } = render(<DesignProposals search={search} />)

    expect(screen.getByRole('heading', { level: 1, name: SCREEN_HEADINGS.top })).toBeInTheDocument()
    expect(proposalOf(container)).toBe('a')
  })

  test('TC-294-03: 切り替えの帯に、いまの案と画面の印を付け、ほかの案へは同じ画面のまま移れる', () => {
    render(<DesignProposals search="?proposal=b&screen=detail" />)

    const bar = screen.getByRole('navigation', { name: '案と画面の切り替え' })
    expect(bar.querySelectorAll('[aria-current="page"]')).toHaveLength(2)
    expect(screen.getByRole('link', { name: '案 B: 夜のバー' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'カクテル詳細' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: '案 C: 白地でシンプル' })).toHaveAttribute('href', '?proposal=c&screen=detail')
  })

  test('TC-294-04: bar=0 なら切り替えの帯を出さない', () => {
    render(<DesignProposals search="?proposal=c&screen=result&bar=0" />)

    expect(screen.queryByRole('navigation', { name: '案と画面の切り替え' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: SCREEN_HEADINGS.result })).toBeInTheDocument()
  })
})
