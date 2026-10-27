import type { ReactNode } from 'react'
import styles from './SiteFrame.module.css'

/**
 * 試作の画面に共通の枠(ヘッダー・フッター)。旧の mainView.vue・header.vue と同じ要素
 * (サイト名・検索の入口・名前検索・登録数・フッターのリンク)を置き、案ごとの見え方を比べられるようにする。
 */
export default function SiteFrame({ children }: { children: ReactNode }) {
  return (
    <div className={styles.frame}>
      <header className={styles.header}>
        <a className={styles.siteTitle} href="#top">
          <svg aria-hidden="true" className={styles.logo} viewBox="0 0 24 24">
            <path d="M3 3h18l-8 9v7h4v2H7v-2h4v-7z" fill="currentColor" />
          </svg>
          <span className={styles.siteName}>宅飲みカクテルデータベース</span>
        </a>
        <button type="button" className={styles.searchButton}>
          検索<span className={styles.searchButtonLong}>をはじめる</span>
        </button>
        <input className={styles.nameSearch} type="search" placeholder="名前で検索" aria-label="名前で検索" />
        <span className={styles.count}>登録数: 5,707</span>
      </header>
      <main className={styles.main}>{children}</main>
      <footer className={styles.footer}>
        <span>宅飲みカクテルデータベース ©ろんぐ</span>
        <a href="#helpme">更新ボランティア募集中...</a>
      </footer>
    </div>
  )
}
