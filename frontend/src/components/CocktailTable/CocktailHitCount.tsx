import type { ReactNode } from 'react'
import styles from './CocktailHitCount.module.css'

/**
 * カクテルの一覧の表の上に出す件数と、並べ替えの案内(旧 Results/children/Cocktail.vue の result_text)。
 * 見出し(「カクテル検索結果」など)は画面ごとに違うので受け取る。検索語句など、件数の後ろに続けるものは children で渡す。
 */
type Props = {
  heading: string
  count: number
  children?: ReactNode
}

export default function CocktailHitCount({ heading, count, children }: Props) {
  return (
    <p className={styles.hitCount}>
      {heading} {count}件ヒットしました。{children}
      <br />
      列名をクリックするとソートします。
    </p>
  )
}
