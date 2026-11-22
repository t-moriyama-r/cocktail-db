import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import SettingForm from '../features/admin-settings/SettingForm'
import styles from '../features/admin-settings/AdminSettings.module.css'

const fetchSettings = () => api.GET('/admin/settings')

/**
 * 管理画面の設定の画面(/admin/settings。#133)。旧に設定の画面は無い(#69 で新しく作る)。
 *
 * - 変えられる項目は、API(GET /api/admin/settings。#132)が返す定義の順に並べる。画面に項目を書き込まない
 * - 画面から変えられないもの(APP_KEY・DB の接続・APP_URL)は、API に無いので、ここに説明を書いておく(#69 の 5)
 */
export default function AdminSettings() {
  usePageTitle('設定')
  const { state, retry } = useApi(fetchSettings)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data }) => (
        <main>
          <h1>設定</h1>
          <p>ここで保存した値は、サーバーの .env の値より優先します。秘密の項目は、値を画面に出しません。</p>
          {data.map((item) => (
            <SettingForm key={item.key} initial={item} />
          ))}
          <section className={styles.fixed} aria-labelledby="admin-settings-fixed">
            <h2 className={styles.itemTitle} id="admin-settings-fixed">
              画面から変えられない設定
            </h2>
            <p>次のものは、サーバーの .env で変えてください。この画面では扱いません。</p>
            <ul>
              <li>
                <code>APP_KEY</code>: この画面で保存した値を暗号化する鍵。変えると、保存した値が読めなくなります
              </li>
              <li>
                DB の接続(<code>DB_</code> で始まるもの): 設定を読む前に要るため
              </li>
              <li>
                <code>APP_URL</code>: サイトの URL
              </li>
            </ul>
          </section>
        </main>
      )}
    </ApiView>
  )
}
