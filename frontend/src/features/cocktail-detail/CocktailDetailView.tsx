import Description from '../../components/Description/Description'
import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import RandomCocktails from '../../components/RandomCocktails/RandomCocktails'
import YouTubeEmbed from '../../components/YouTubeEmbed/YouTubeEmbed'
import CocktailAliases from './CocktailAliases'
import CocktailDataList from './CocktailDataList'
import styles from './CocktailDetail.module.css'
import type { CocktailDetailData } from './CocktailDetail.types'
import CocktailNotice from './CocktailNotice'
import IngredientTable from './IngredientTable'
import SameNameCocktails from './SameNameCocktails'
import { cocktailTitle } from './cocktailDetailLabels'

type Props = {
  cocktail: CocktailDetailData
}

/**
 * カクテルの詳細(旧 Detail/CocktailDetail.vue)。上から、見出し・ベースなどの欄・グラス・説明文・
 * YouTube・材料の表・Note の順に出す。旧と同じくパンくずは無い。
 * その下に、同じ名前のカクテル・別名・同じレシピのカクテル・材料が似たカクテル・同じベースのカクテルを出す(#109)。
 * 同じ名前・別名・同じレシピは 0 件なら欄を出さず、材料が似た・同じベースは旧と同じく 0 件でも見出しを出す。
 * 別名・同じレシピ(#82)は旧に無く、同じ名前の欄の後ろに置く。画像・アフィリエイトは #110 で足す。
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
      <SameNameCocktails cocktailId={cocktail.id} />
      {cocktail.aliases.length > 0 && <CocktailAliases aliases={cocktail.aliases} />}
      {cocktail.same_recipe.length > 0 && (
        <CocktailNotice message="同じレシピのカクテルがあります！" cocktails={cocktail.same_recipe} />
      )}
      <div className={`${styles.related} ${styles.similar}`}>
        <RandomCocktails origin={{ kind: 'cocktail', id: cocktail.id }} />
      </div>
      <div className={`${styles.related} ${styles.sameBase}`}>
        <RandomCocktails origin={{ kind: 'base', id: cocktail.base.id }} />
      </div>
    </main>
  )
}
