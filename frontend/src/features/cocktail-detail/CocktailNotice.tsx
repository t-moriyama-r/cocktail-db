import { useId } from 'react'
import CocktailTable from '../../components/CocktailTable/CocktailTable'
import type { CocktailTableRow } from '../../components/CocktailTable/CocktailTable.types'
import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import styles from './CocktailDetail.module.css'

type Props = {
  /** 電球のアイコンの後ろに出す文言。表の名前にも使う */
  message: string
  cocktails: readonly CocktailTableRow[]
}

/**
 * 電球のアイコンと文言の下に、カクテルの一覧の表を出す欄(旧 Detail/CocktailDetail.vue の #same_name_cocktail_container)。
 * 同じ名前のカクテル(旧と同じ)と、同じレシピのカクテル(#82。旧に無い)で使う。0 件のときは呼ぶ側が出さない。
 */
export default function CocktailNotice({ message, cocktails }: Props) {
  const headingId = useId()
  return (
    <section className={`${styles.related} ${styles.sameCocktail}`} aria-labelledby={headingId}>
      <h2 className={styles.noticeHeading} id={headingId}>
        <FontAwesomeIcon icon="lightbulb" label={null} />
        {message}
      </h2>
      <CocktailTable cocktails={cocktails} sort={{ mode: 'local' }} label={message} />
    </section>
  )
}
