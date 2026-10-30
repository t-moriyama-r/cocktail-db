import { useCallback, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import CocktailSearch from '../../features/search/CocktailSearch'
import { defaultSearchFormValues, type SearchFormValues } from '../../features/search/searchForm'
import FontAwesomeIcon from '../CocktailTable/FontAwesomeIcon'
import NameSearchField from './NameSearchField'
import RegisteredCount from './RegisteredCount'
import SearchModal from './SearchModal'
import styles from './SiteHeader.module.css'

/** ヘッダーに出すサイト名(旧と同じ。ブラウザのタイトルの SITE_TITLE と違い、末尾に (RC) を付けない) */
const SITE_NAME = '宅飲みカクテルデータベース'

/**
 * 上に固定のヘッダー(旧 Header/header.vue)。左から、サイト名・「検索をはじめる」・名前検索・右端に登録数。
 *
 * - サイト名: カクテルのアイコンと名前。名前は幅 960px 以上だけ出す
 * - 「検索をはじめる」: 幅 600px 以上で「検索」、960px 以上で「をはじめる」も出す。押すと条件検索のモーダルを開く。
 *   モーダルの中は条件検索のフォーム(#307)。フォームの値はここで持ち、閉じて開き直しても残す(旧の v-dialog も中身を残していた)
 * - 登録数: 幅 600px 以上だけ出す
 */
export default function SiteHeader() {
  const [modalOpen, setModalOpen] = useState(false)
  const closeModal = useCallback(() => setModalOpen(false), [])
  const [searchValues, setSearchValues] = useState<SearchFormValues>(defaultSearchFormValues)
  const navigate = useNavigate()

  // 画面を移ったらモーダルを閉じる(フォームで検索して結果の画面へ移ったとき、など)。
  // 描画の中で前の URL と比べて閉じる(React の「前の値から state を直す」書き方。effect で閉じると、閉じる前の描画が 1 回余分に入る)
  const { pathname, search } = useLocation()
  const url = `${pathname}${search}`
  const [previousUrl, setPreviousUrl] = useState(url)
  if (url !== previousUrl) {
    setPreviousUrl(url)
    setModalOpen(false)
  }

  return (
    <header className={styles.header}>
      <Link to="/" className={styles.siteName} aria-label={SITE_NAME}>
        <FontAwesomeIcon icon="cocktail" label={SITE_NAME} />
        <span className={styles.mdUp}>{SITE_NAME}</span>
      </Link>
      <button type="button" className={styles.searchStart} aria-label="検索をはじめる" onClick={() => setModalOpen(true)}>
        <FontAwesomeIcon icon="search" label="検索をはじめる" />
        <span className={styles.smUp}>検索</span>
        <span className={styles.mdUp}>をはじめる</span>
      </button>
      <NameSearchField />
      <div className={styles.countArea}>
        <RegisteredCount />
      </div>
      {modalOpen && (
        <SearchModal onClose={closeModal}>
          <CocktailSearch
            values={searchValues}
            onValuesChange={setSearchValues}
            inModal
            // 条件を URL に入れて結果の画面で検索するのは #102。それまでは結果の画面へ移って閉じるだけ
            onSubmit={() => {
              navigate('/result/Cocktails')
              closeModal()
            }}
            onNavigate={closeModal}
          />
        </SearchModal>
      )}
    </header>
  )
}
