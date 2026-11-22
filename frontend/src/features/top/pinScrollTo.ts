/** ページ内リンクで飛んだ先を、画面の先頭に留め続ける時間の上限。読み込みが遅くても、これより後には位置を動かさない */
export const PIN_TIMEOUT_MS = 10_000

/** 利用者が自分で画面を動かそうとしたとみなす操作。どれかが起きたら、飛んだ先に留めるのをやめる */
const USER_SCROLL_EVENTS = ['wheel', 'touchstart', 'keydown', 'mousedown'] as const

/**
 * target を画面の先頭(scroll-margin-top の分を空けた位置)に出し、ページの大きさが変わるたびに出し直す(#646)。
 *
 * トップは欄ごとに API を呼ぶので、読み込み中にページ内リンクで飛ぶと、ページが短いまま止まり、
 * 後から届いた上の欄(新作動画・一覧)の伸びた分だけ、飛んだ先が画面の外へ押し出される。
 * その伸びを追って出し直す。利用者が自分で動かそうとしたとき・PIN_TIMEOUT_MS たったとき・返した関数を呼んだときにやめる。
 * ResizeObserver の無い環境では何もしない(ブラウザのページ内リンクの動きだけになる)。
 */
export function pinScrollTo(target: HTMLElement): () => void {
  if (typeof ResizeObserver === 'undefined') {
    return () => {}
  }

  const observer = new ResizeObserver(() => target.scrollIntoView({ block: 'start' }))
  let timer: ReturnType<typeof setTimeout> | undefined
  const stop = () => {
    observer.disconnect()
    clearTimeout(timer)
    for (const type of USER_SCROLL_EVENTS) {
      window.removeEventListener(type, stop, true)
    }
  }

  // ページ全体の大きさを見る(飛んだ先より上のどの欄が伸びても拾う)。最初の 1 回は observe した直後に呼ばれる
  observer.observe(document.body)
  for (const type of USER_SCROLL_EVENTS) {
    window.addEventListener(type, stop, { capture: true, passive: true })
  }
  timer = setTimeout(stop, PIN_TIMEOUT_MS)
  return stop
}
