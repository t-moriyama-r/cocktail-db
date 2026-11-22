import { useEffect } from 'react'
import { useLocation } from 'react-router'

/**
 * GA4 のタグ(gtag.js)が置く関数。サーバーが測定 ID のあるときだけ HTML にタグを入れる(#59。backend の Ga4Tag)ので、
 * 測定 ID が未設定なら無い。
 */
type Gtag = (command: 'event', eventName: 'page_view', params: { page_location: string; page_title: string }) => void

declare global {
  interface Window {
    gtag?: Gtag
  }
}

/**
 * 公開ページの画面を開いたとき・移ったときに、GA4 に page_view を送る(#59。決めたことは docs/decisions/analytics.md)。
 * SPA は画面を移っても HTML を読み直さないので、タグは最初の page_view も送らない設定にして、ここで送る。
 *
 * 移ったかどうかは URL のパスとクエリで見る(同じ URL へのリンクを押しても送らない)。
 * ページ名(page_title)は、移った画面がタイトルを書き換えた後(子の useEffect は親より先に動く)の document.title。
 * API の応答を待ってからタイトルを決める画面(カクテルの詳細など)は、まだサイト名のときに送る。
 */
export function usePageViewTracking() {
  const { pathname, search } = useLocation()

  useEffect(() => {
    window.gtag?.('event', 'page_view', {
      page_location: `${window.location.origin}${pathname}${search}`,
      page_title: document.title,
    })
  }, [pathname, search])
}
