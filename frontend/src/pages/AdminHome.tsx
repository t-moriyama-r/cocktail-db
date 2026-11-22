import { usePageTitle } from '../components/SiteLayout/usePageTitle'

/**
 * 管理画面のメニュー(/admin)。旧の /admin はリンクを縦に並べたメニューのページだった。
 * 新はメニューを管理画面の枠(AdminLayout)がどの画面にも出すので、ここは入り口の見出しだけを出す(#119)。
 */
export default function AdminHome() {
  usePageTitle('管理画面')
  return (
    <main>
      <h1>管理画面</h1>
      <p>メニューから、編集する画面を選んでください。</p>
    </main>
  )
}
