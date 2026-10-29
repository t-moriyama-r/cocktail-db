import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import Description, { type DescriptionSegment } from './Description'

const renderDescription = (segments: readonly DescriptionSegment[] | null | undefined) =>
  render(
    <MemoryRouter>
      <Description segments={segments} />
    </MemoryRouter>,
  )

const link = (type: Exclude<DescriptionSegment['type'], 'text'>, id: number, text: string, hasYoutube = false) =>
  ({ type, id, text, has_youtube: hasYoutube }) as const

describe('Description', () => {
  test.each([
    ['TC-301-01', 'ノード', link('node', 12, 'ジン'), '/category/12'],
    ['TC-301-02', 'カクテル', link('cocktail', 3, 'マティーニ'), '/detail/cocktail/3'],
    ['TC-301-03', '銘柄', link('brand', 5, 'ビーフィーター'), '/detail/brand/5'],
    ['TC-301-04', '用語', link('dictionary', 7, 'ステア'), '/dictionary/7'],
  ])('%s: %s のリンクの区切りは、指す先の名前で %s へのリンクにする', (_id, _label, segment, href) => {
    renderDescription([segment])

    expect(screen.getByRole('link', { name: segment.text })).toHaveAttribute('href', href)
  })

  test('TC-301-05: リンク先に動画があれば、リンクを動画ありの色にする', () => {
    renderDescription([link('cocktail', 3, 'マティーニ', true)])

    expect(screen.getByRole('link', { name: 'マティーニ' }).className).toMatch(/hasMovie/)
  })

  test('TC-301-06: リンク先に動画が無ければ、ふつうのリンクの色のままにする', () => {
    renderDescription([link('cocktail', 3, 'マティーニ', false)])

    expect(screen.getByRole('link', { name: 'マティーニ' }).className).not.toMatch(/hasMovie/)
  })

  test.each([
    ['\\n', '1行目\n2行目'],
    ['\\r\\n', '1行目\r\n2行目'],
    ['\\r', '1行目\r2行目'],
  ])('TC-301-07: 文字の区切りの改行(%s)は、1 つの改行として出す', (_label, text) => {
    const { container } = renderDescription([{ type: 'text', text }])

    const breaks = container.querySelectorAll('br')
    expect(breaks).toHaveLength(1)
    expect(breaks[0].previousSibling?.textContent).toBe('1行目')
    expect(breaks[0].nextSibling?.textContent).toBe('2行目')
  })

  test('TC-301-08: 改行が 2 つ続けば、空の行を 1 つ挟んで出す', () => {
    const { container } = renderDescription([{ type: 'text', text: '1行目\n\n3行目' }])

    expect(container.querySelectorAll('br')).toHaveLength(2)
  })

  test('TC-301-09: <script> を含む文字は、HTML として描かず、書いたままの文字として出す', () => {
    const { container } = renderDescription([{ type: 'text', text: '<script>alert(1)</script>' }])

    expect(container.querySelector('script')).toBeNull()
    expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument()
  })

  test('TC-301-10: タグや文字参照(<b>・&amp;)を含む文字も、書いたままの文字として出す', () => {
    const { container } = renderDescription([{ type: 'text', text: '<b>太字</b> &amp; 記号' }])

    expect(container.querySelector('b')).toBeNull()
    expect(screen.getByText('<b>太字</b> &amp; 記号')).toBeInTheDocument()
  })

  test('TC-301-11: 文字とリンクの区切りを、並びの順に続けて出す', () => {
    const { container } = renderDescription([
      { type: 'text', text: '前の文 ' },
      link('cocktail', 3, 'マティーニ', true),
      { type: 'text', text: ' 後の文' },
    ])

    expect(container).toHaveTextContent('前の文 マティーニ 後の文')
    expect(screen.getByRole('link', { name: 'マティーニ' })).toHaveAttribute('href', '/detail/cocktail/3')
  })

  test.each([
    ['null', null],
    ['空の並び', []],
  ])('TC-301-12: 説明文が %s なら何も出さない', (_label, segments) => {
    const { container } = renderDescription(segments)

    expect(container).toBeEmptyDOMElement()
  })
})
