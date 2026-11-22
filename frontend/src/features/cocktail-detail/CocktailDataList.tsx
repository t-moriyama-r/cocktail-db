import { Link } from 'react-router'
import MethodIcon from '../../components/CocktailTable/MethodIcon'
import { sweetDegreeLabel } from '../../components/CocktailTable/sweetDegreeLabel'
import styles from './CocktailDetail.module.css'
import type { CocktailDetailData } from './CocktailDetail.types'
import { glassLabel, rateLabel, strengthPercent } from './cocktailDetailLabels'
import { categoryPath, dictionaryPath } from '../../utils/detailPaths'

type Props = {
  cocktail: CocktailDetailData
}

/**
 * カクテルの詳細の、ベース・製法・甘さ・おすすめ度・度数と、グラスの欄(旧 Detail/CocktailDetail.vue の #cocktail_data)。
 *
 * 旧は幅 600px 以上と未満で別の表を描き分けていた。新は同じ並びを CSS だけで組み替え、600px 以上は 5 つを 1 行に、
 * 未満はベースだけを 1 行目に置き、残りの 4 つを 2 行目に並べる。
 */
export default function CocktailDataList({ cocktail }: Props) {
  const { base, method, glass, ice } = cocktail
  return (
    <div className={styles.data}>
      <dl className={styles.dataList}>
        <div className={`${styles.dataItem} ${styles.baseItem}`}>
          <dt>ベース</dt>
          <dd>
            <Link to={categoryPath(base.id)}>
              {base.name}({base.name_eng})
            </Link>
          </dd>
        </div>
        <div className={styles.dataItem}>
          <dt>製法</dt>
          <dd>
            <MethodIcon method={method} />
            {/* 用語の無い技法(dictionary_id が null)は、リンク先が無いので名前だけを出す */}
            {method.dictionary_id === null ? (
              method.name
            ) : (
              <Link to={dictionaryPath(method.dictionary_id)}>{method.name}</Link>
            )}
          </dd>
        </div>
        <div className={styles.dataItem}>
          <dt>甘さ</dt>
          <dd>{sweetDegreeLabel(cocktail.sweet_degree)}</dd>
        </div>
        <div className={styles.dataItem}>
          <dt>おすすめ度</dt>
          <dd className={cocktail.rate ? undefined : styles.noRate}>{rateLabel(cocktail.rate)}</dd>
        </div>
        <div className={styles.dataItem}>
          <dt>度数</dt>
          <dd>{strengthPercent(cocktail.strength)}</dd>
        </div>
      </dl>
      {glass && (
        <dl className={styles.dataList}>
          <div className={styles.dataItem}>
            <dt>グラス</dt>
            <dd>
              <Link to={dictionaryPath(glass.id)}>{glassLabel(glass, ice)}</Link>
            </dd>
          </div>
        </dl>
      )}
    </div>
  )
}
