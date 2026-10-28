import { useEffect } from 'react'

/** ページのタイトルの後ろに付けるサイト名(旧 app.js の vue-head の complement) */
export const SITE_TITLE = '宅飲みカクテルデータベース(RC)'

/**
 * ページ名からタイトルを作る。旧(vue-head)と同じく `〈ページ名〉 ｜ 〈サイト名〉` にする。
 * 旧の vue-head は `inner + ' ' + separator + ' ' + complement` を trim して出していたので、区切りの全角の `｜` の前後に半角の空白が入る。
 * ページ名が無い(空)ときはサイト名だけにする(旧で head を持たない画面は、vue-head がサイト名に戻していた)。
 */
export const formatPageTitle = (pageName?: string) => (pageName ? `${pageName} ｜ ${SITE_TITLE}` : SITE_TITLE)

/**
 * 画面がページ名を渡し、ブラウザのタイトル(document.title)をそのページのものにする。
 * 画面を離れたらサイト名だけに戻すので、ページ名を持たない画面はこれを呼ばなくてよい。
 */
export function usePageTitle(pageName?: string) {
  useEffect(() => {
    document.title = formatPageTitle(pageName)
    return () => {
      document.title = SITE_TITLE
    }
  }, [pageName])
}
