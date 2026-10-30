import { render, screen } from '@testing-library/react'
import { describe, expect, test } from 'vitest'
import HelpMe from './HelpMe'
import { apiRequests } from '../test/api'

const main = () => screen.getByRole('main')

describe('HelpMe', () => {
  test('TC-57-01: 見出しは旧と同じ「当DBの更新ボランティア募集について」', () => {
    render(<HelpMe />)

    expect(screen.getByRole('heading', { level: 1, name: '当DBの更新ボランティア募集について' })).toBeInTheDocument()
  })

  test.each([
    'このDBは見てわかる通り、カクテルだけでなくそれに付随したお酒すべての情報を一応は網羅する(したい)DBになります。',
    '特にスピリッツやウイスキー等は僕自身が苦手であるので進んで飲みたくはないです(笑)',
    '＜募集要項＞',
    '1.日常的にスピリッツ系を嗜まれる方。 具体的には、ラム、ウォッカ、テキーラ、ジン、ブランデー、ウイスキーのことです。',
    '2.twitterアカウントを持っている方。',
    '文責者としてリンクぐらいは貼りたいのでできれば公開アカウントが望ましいです。売名目的にどうぞ',
    'その他のログイン方法は僕が使わないので実装してません。パスワードリセットを実装したくなかっただけです(笑)',
    'もし興味があればtwitterのDMかなんかで教えて下さい。もしそんな酔狂な方がいれば仕様を考えます(笑)',
  ])('TC-57-02: 旧の文を写す「%s」', (text) => {
    render(<HelpMe />)

    expect(main()).toHaveTextContent(text, { normalizeWhitespace: true })
  })

  test('TC-57-03: 管理者ページのログインは、twitter アカウントではなくパスキーと書く', () => {
    render(<HelpMe />)

    expect(main()).toHaveTextContent('管理者ページのログインにはパスキーを用います。')
    expect(main()).not.toHaveTextContent('ログインにtwitterアカウントを用います')
  })

  test.each([
    ['スピリッツやウイスキー等は僕自身が苦手である', 'B'],
    ['メモ用紙代わりに使ってみたい', 'B'],
    ['1.日常的にスピリッツ系を嗜まれる方。', 'B'],
    ['2.twitterアカウントを持っている方。', 'B'],
    ['そもそもこのDB自体は収益をあげることを目的にしてません', 'S'],
    ['僕がわからないので', 'S'],
    ['売名目的にどうぞ', 'S'],
  ])('TC-57-04: 「%s」は旧と同じく <%s> で出す', (text, tag) => {
    render(<HelpMe />)

    expect(screen.getByText(text).tagName).toBe(tag)
  })

  test('TC-57-05: API を呼ばない', () => {
    render(<HelpMe />)

    expect(apiRequests()).toEqual([])
  })
})
