import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import ParaphraseSection from '../features/admin-paraphrases/ParaphraseSection'
import SkipWordSection from '../features/admin-paraphrases/SkipWordSection'
import styles from './AdminParaphrases.module.css'

/**
 * 管理画面の言い換え辞書・スキップ語(/admin/paraphrases。#67)。メニューの 1 項目「言い換え・スキップ語」で開き、
 * 2 つの一覧とフォームを上下に並べる。旧は言い換えの画面(Admin/Super/ParaPhraseList.vue)だけで保存のボタンが無く、
 * スキップ語の画面は無かった。相手の API は #316
 */
export default function AdminParaphrases() {
  usePageTitle('言い換え・スキップ語')
  return (
    <main className={styles.page}>
      <h1 className={styles.title}>言い換え・スキップ語</h1>
      <ParaphraseSection />
      <SkipWordSection />
    </main>
  )
}
