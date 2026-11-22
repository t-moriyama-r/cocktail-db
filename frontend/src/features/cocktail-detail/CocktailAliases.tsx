import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import styles from './CocktailDetail.module.css'
import type { CocktailDetailData } from './CocktailDetail.types'

type Props = {
  aliases: CocktailDetailData['aliases']
}

/**
 * カクテルの別名(#82。旧に無い)。「別名: 〈別名〉、〈別名〉」と、登録した順(API の順)に 1 行で出す。
 * 0 件のときは呼ぶ側が出さない。
 */
export default function CocktailAliases({ aliases }: Props) {
  return (
    <section className={`${styles.related} ${styles.sameCocktail}`} aria-label="別名">
      <p className={styles.aliases}>
        <FontAwesomeIcon icon="tag" label={null} />
        別名: {aliases.map((alias) => alias.name).join('、')}
      </p>
    </section>
  )
}
