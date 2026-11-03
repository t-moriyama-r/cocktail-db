import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import CreditedImage from './CreditedImage'

const SRC = 'https://example.com/storage/sample.jpg'

/** 画面の URL から相対に解くと、どこを取りに行くか(`/category/2` で開いた画面に置いたとき) */
const resolvedOnPage = (src: string | null) => new URL(src ?? '', 'https://example.com/category/2').href

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

  test.each([
    ['英数字のファイル名', 'sample.jpg', '/storage/sample.jpg'],
    ['空白と日本語の入ったファイル名', 'ジン トニック.jpg', '/storage/ジン トニック.jpg'],
  ])('TC-585-01: 画像の列が%sなら、/storage/ の下を指す', (_label, image, expected) => {
    render(<CreditedImage src={image} alt="ジン" creditUrl={null} creditText={null} />)

    const img = screen.getByRole('img', { name: 'ジン' })
    expect(img).toHaveAttribute('src', expected)
    // 画面の URL(/category/2)によらず、サイトの /storage/ の下を取りに行く
    expect(resolvedOnPage(img.getAttribute('src'))).toBe(new URL(expected, 'https://example.com/').href)
  })

  test.each([
    ['https', 'https://example.com/images/gin.jpg'],
    ['http', 'http://example.com/images/gin.jpg'],
    ['大文字の HTTPS', 'HTTPS://example.com/images/gin.jpg'],
  ])('TC-585-02: 画像の列が外部の URL(%s)なら、その URL のまま出す', (_label, image) => {
    render(<CreditedImage src={image} alt="ジン" creditUrl={null} creditText={null} />)

    expect(screen.getByRole('img', { name: 'ジン' })).toHaveAttribute('src', image)
  })
})
