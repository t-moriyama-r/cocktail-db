import { Link } from 'react-router'
import styles from './SiteLayout.module.css'

/** 作者のサイトと X(旧 mainView.vue のフッターのリンク先。旧の本番の画面にも出ていた公開の URL) */
const AUTHOR_SITE_URL = 'https://trc-vlackhistory.net/'
const AUTHOR_X_URL = 'https://twitter.com/virtualDollym'

/**
 * フッター(旧 mainView.vue の v-footer)。左に著作の表示と作者のサイト・X へのリンク、右に更新ボランティア募集へのリンク。
 * 外のサイトは旧と同じく新しいタブで開く。
 */
export default function SiteFooter() {
  return (
    <footer className={styles.footer}>
      <div className={styles.footerMain}>
        宅飲みカクテルデータベース ©
        <a href={AUTHOR_SITE_URL} target="_blank" rel="noopener noreferrer">
          ろんぐ
        </a>{' '}
        <a href={AUTHOR_X_URL} target="_blank" rel="noopener noreferrer" className={styles.xLink}>
          <XIcon />
        </a>
      </div>
      <div>
        <Link to="/helpme">更新ボランティア募集中...</Link>
      </div>
    </footer>
  )
}

/**
 * X のロゴ。色は文字の色(currentColor)、高さは文字にそろえる。
 * 形は Simple Icons の x(CC0 1.0 https://simpleicons.org/)
 */
function XIcon() {
  return (
    <svg viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" role="img" aria-label="作者の X" focusable="false">
      <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z" />
    </svg>
  )
}
