import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import YouTubeEmbed from './YouTubeEmbed'

describe('YouTubeEmbed', () => {
  test('TC-302-01: 開始位置が 0 秒なら、埋め込みの URL に start を付けない', () => {
    render(<YouTubeEmbed videoId="xa8mIHLpvNM" startSeconds={0} />)

    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/xa8mIHLpvNM')
  })

  test('TC-302-02: 開始位置が 1 秒なら、?start=1 を付ける', () => {
    render(<YouTubeEmbed videoId="xa8mIHLpvNM" startSeconds={1} />)

    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute(
      'src',
      'https://www.youtube.com/embed/xa8mIHLpvNM?start=1',
    )
  })

  test('TC-302-03: 開始位置が 3661 秒(1 時間 1 分 1 秒)なら、?start=3661 を付ける', () => {
    render(<YouTubeEmbed videoId="xa8mIHLpvNM" startSeconds={3661} />)

    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute(
      'src',
      'https://www.youtube.com/embed/xa8mIHLpvNM?start=3661',
    )
  })

  test('TC-302-04: 開始位置を渡さなければ、start を付けない', () => {
    render(<YouTubeEmbed videoId="xa8mIHLpvNM" />)

    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/xa8mIHLpvNM')
  })

  test.each([
    ['null', null],
    ['空文字', ''],
  ])('TC-302-05: 動画の ID が %s なら何も出さない', (_label, videoId) => {
    const { container } = render(<YouTubeEmbed videoId={videoId} startSeconds={10} />)

    expect(container).toBeEmptyDOMElement()
  })

  test('TC-302-06: 旧と同じく、自動再生・暗号化メディアと全画面表示を許す', () => {
    render(<YouTubeEmbed videoId="xa8mIHLpvNM" />)

    const iframe = screen.getByTitle('YouTube の動画')
    expect(iframe).toHaveAttribute('allow', 'autoplay; encrypted-media')
    expect(iframe).toHaveAttribute('allowfullscreen')
  })
})
