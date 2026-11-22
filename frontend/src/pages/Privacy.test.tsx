import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import App from '../App'

// プライバシーポリシー(/privacy。#59。旧に無い)。決めたことは docs/decisions/analytics.md

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

describe('プライバシーポリシー', () => {
  test('TC-59-09: /privacy に、GA4(Google アナリティクス)を使っていることと、Cookie で集めること・送らない方法を書く', async () => {
    renderAt('/privacy')

    const main = await screen.findByRole('main')
    expect(screen.getByRole('heading', { level: 1, name: 'プライバシーポリシー' })).toBeInTheDocument()
    expect(main).toHaveTextContent('「Google アナリティクス(GA4)」を使っています')
    expect(main).toHaveTextContent('Cookie を使い')
    expect(screen.getByRole('link', { name: 'Google アナリティクス オプトアウト アドオン' })).toHaveAttribute(
      'href',
      'https://tools.google.com/dlpage/gaoptout?hl=ja',
    )
    await waitFor(() => expect(document.title).toBe('プライバシーポリシー ｜ 宅飲みカクテルデータベース(RC)'))
  })

  test('TC-59-10: Google の案内へのリンクは、新しいタブで開く', async () => {
    renderAt('/privacy')

    await screen.findByRole('heading', { level: 1, name: 'プライバシーポリシー' })
    const links = [
      ['Google のサービスを使うサイトやアプリから集めた情報の、Google による使い方', 'https://policies.google.com/technologies/partner-sites?hl=ja'],
      ['Google のプライバシーポリシー', 'https://policies.google.com/privacy?hl=ja'],
      ['Google アナリティクス オプトアウト アドオン', 'https://tools.google.com/dlpage/gaoptout?hl=ja'],
    ]
    for (const [name, href] of links) {
      const link = screen.getByRole('link', { name })
      expect(link).toHaveAttribute('href', href)
      expect(link).toHaveAttribute('target', '_blank')
      expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    }
  })
})
