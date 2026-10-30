import { useEffect, useLayoutEffect } from 'react'
import { Outlet, useLocation } from 'react-router'
import SearchFormValuesProvider from '../../features/search/SearchFormValuesProvider'
import SiteHeader from '../SiteHeader/SiteHeader'
import SiteFooter from './SiteFooter'
import styles from './SiteLayout.module.css'
import { SITE_TITLE } from './usePageTitle'

/**
 * すべての公開ページを包む枠(旧 components/mainView.vue)。上に固定のヘッダー、本文(各ページ)、フッターを置く。
 * ヘッダー(名前検索・条件検索・ランダムウォーク)は SiteHeader(#99)。
 * ヘッダーのモーダルの条件検索のフォームの値は、結果の画面(#102)も書き換えるので、枠で持つ(SearchFormValuesProvider)。
 */
export default function SiteLayout() {
  useScrollToTopOnNavigate()
  // 最初に開いた画面がページ名を渡さないときもサイト名だけを出す(HTML に書いた仮のタイトルを残さない)。
  // 画面の usePageTitle(useEffect)より先に動くよう useLayoutEffect にする(親の useEffect は子の後に動き、ページ名を消してしまう)
  useLayoutEffect(() => {
    document.title = SITE_TITLE
  }, [])

  return (
    <SearchFormValuesProvider>
      <div className={styles.root}>
        <SiteHeader />
        <div className={styles.content}>
          <Outlet />
        </div>
        <SiteFooter />
      </div>
    </SearchFormValuesProvider>
  )
}

/**
 * 画面を移ったら一番上から表示する(旧 app.js の scrollBehavior が、どの移り方でも { x: 0, y: 0 } を返していたのと同じ)。
 * 旧は戻る・進むでも前の位置に戻さなかったので、ブラウザによる位置の復元も止める。
 * 同じ URL への移動では動かさない(旧の vue-router は同じ場所への移動を取りやめ、スクロールもしなかった)。
 */
function useScrollToTopOnNavigate() {
  const { pathname, search } = useLocation()

  useEffect(() => {
    if ('scrollRestoration' in window.history) {
      window.history.scrollRestoration = 'manual'
    }
  }, [])

  // 描画の前に戻し、前の位置の新しい画面が一瞬見えないようにする
  useLayoutEffect(() => {
    window.scrollTo(0, 0)
  }, [pathname, search])
}
