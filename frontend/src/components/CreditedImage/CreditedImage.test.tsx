import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import CreditedImage from './CreditedImage'

const SRC = 'https://example.com/storage/sample.jpg'

describe('CreditedImage', () => {
  test.each([
    ['null', null],
    ['空文字', ''],
  ])('TC-302-07: 画像が %s なら、出典があっても何も出さない', (_label, src) => {
    const { container } = render(
      <CreditedImage src={src} alt="スクリュードライバー" creditUrl="https://example.com/" creditText="出典" />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  test('TC-302-08: 出典の URL があれば、出典の文字をそのリンクにする', () => {
    render(
      <CreditedImage
        src={SRC}
        alt="スクリュードライバー"
        creditUrl="https://example.com/credit"
        creditText="日本カクテルスペシャリスト協会"
      />,
    )

    expect(screen.getByRole('img', { name: 'スクリュードライバー' })).toHaveAttribute('src', SRC)
    expect(screen.getByRole('link', { name: '日本カクテルスペシャリスト協会' })).toHaveAttribute(
      'href',
      'https://example.com/credit',
    )
  })

  test('TC-302-09: 出典の URL が無ければ、出典の文字をリンクにせず出す', () => {
    render(<CreditedImage src={SRC} alt="スクリュードライバー" creditUrl={null} creditText="出典の文字" />)

    expect(screen.getByText('出典の文字')).toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  test('TC-302-10: 出典の URL も文字も無ければ、画像だけを出す', () => {
    const { container } = render(<CreditedImage src={SRC} alt="グラスホッパー" creditUrl={null} creditText={null} />)

    expect(screen.getByRole('img', { name: 'グラスホッパー' })).toBeInTheDocument()
    expect(container.querySelector('a')).toBeNull()
    expect(container.querySelector('figcaption')).toBeEmptyDOMElement()
  })

  test('TC-302-11: 出典の URL があって文字が無ければ、旧と同じく中身の無いリンクを置く', () => {
    const { container } = render(
      <CreditedImage src={SRC} alt="ヴァイオレット・フィズ" creditUrl="https://example.com/credit" creditText={null} />,
    )

    const link = container.querySelector('a')
    expect(link).toHaveAttribute('href', 'https://example.com/credit')
    expect(link).toBeEmptyDOMElement()
  })
})
