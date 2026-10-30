import Description from '../../components/Description/Description'
import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import YouTubeEmbed from '../../components/YouTubeEmbed/YouTubeEmbed'
import CocktailDataList from './CocktailDataList'
import styles from './CocktailDetail.module.css'
import type { CocktailDetailData } from './CocktailDetail.types'
import IngredientTable from './IngredientTable'
import { cocktailTitle } from './cocktailDetailLabels'

type Props = {
  cocktail: CocktailDetailData
}

/**
 * カクテルの詳細の、基本情報と材料(旧 Detail/CocktailDetail.vue)。上から、見出し・ベースなどの欄・グラス・説明文・
 * YouTube・材料の表・Note の順に出す。旧と同じくパンくずは無い。
 * 同じ名前・材料が似た・同じベース・別名・同じレシピのカクテルは #109、画像・アフィリエイトは #110 で足す。
 */
export default function CocktailDetailView({ cocktail }: Props) {
  const hasNote = !!cocktail.note && cocktail.note.length > 0
  return (
    <main className={styles.page}>
      <h1 className={styles.title}>{cocktailTitle(cocktail)}</h1>
      <CocktailDataList cocktail={cocktail} />
      <section className={styles.description}>
        {/* 旧と同じく、説明文の有無によらず 960px 未満で区切りを出す */}
        <div className={styles.descriptionDivider}>
          <span className={styles.dividerLine} />
          <span className={styles.dividerLabel}>§Description§</span>
          <span className={styles.dividerLine} />
        </div>
        <Description segments={cocktail.description} />
      </section>
      {cocktail.youtube && (
        <div className={styles.youtube}>
          <YouTubeEmbed videoId={cocktail.youtube} startSeconds={cocktail.youtube_start_seconds} />
        </div>
      )}
      <div className={styles.recipe}>
        <IngredientTable ingredients={cocktail.ingredients} />
        {hasNote && (
          <section className={styles.note} aria-label="Note">
            <div>
              <FontAwesomeIcon icon="pencil-alt" label={null} />
              Note
            </div>
            <div className={styles.noteText}>
              <Description segments={cocktail.note} />
            </div>
          </section>
        )}
      </div>
    </main>
  )
}
