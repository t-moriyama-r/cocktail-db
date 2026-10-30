import { useState } from 'react'
import { Link } from 'react-router'
import styles from './CocktailTable.module.css'
import type { CocktailSort, CocktailSortKey, CocktailTableRow, CocktailTableSortMode } from './CocktailTable.types'
import FontAwesomeIcon from './FontAwesomeIcon'
import MethodIcon from './MethodIcon'
import SortableHeader from './SortableHeader'
import { nextCocktailSort } from './nextCocktailSort'
import { rateStars } from './rateStars'
import { sortCocktails } from './sortCocktails'
import { strengthLabel } from './strengthLabel'
import { sweetDegreeLabel } from './sweetDegreeLabel'
import { categoryPath, cocktailPath } from '../../utils/detailPaths'
import { youtubeWatchUrl } from '../../utils/youtubeWatchUrl'

/**
 * カクテルの一覧の表(旧 Results/Tables/Cocktails.vue)。
 *
 * 旧は開いたときの幅で PC 用とスマホ用の別の DOM を描き分け、回転しても変わらなかった。
 * 新は同じ表を CSS だけで組み替え、幅が変わればその場で切り替える(600px 未満で名前の段と数値の段の 2 段にする)。
 * 並べ替えは、渡した一覧を表の中で並べ替える形と、並びを外で決めて渡す形の 2 つを持つ。
 */
type Props = {
  cocktails: readonly CocktailTableRow[]
  sort: CocktailTableSortMode
  /** 表の名前(読み上げに使う)。画面に出す見出しは表の外に置く */
  label: string
}

export default function CocktailTable({ cocktails, sort, label }: Props) {
  const [localSort, setLocalSort] = useState<CocktailSort | null>(null)
  const current = sort.mode === 'local' ? localSort : sort.sort
  const rows = sort.mode === 'local' ? sortCocktails(cocktails, localSort) : cocktails

  const onSort = (key: CocktailSortKey) => {
    const next = nextCocktailSort(current, key)
    if (sort.mode === 'local') {
      setLocalSort(next)
    } else {
      sort.onSortChange(next)
    }
  }

  const header = (key: CocktailSortKey, className: string, text: string) => (
    <SortableHeader key={key} sortKey={key} current={current} className={className} onSort={onSort}>
      {text}
    </SortableHeader>
  )

  return (
    <table className={styles.table} aria-label={label}>
      <thead>
        <tr>
          {header('method', styles.method, '製法')}
          {header('name', styles.name, '名前')}
          {header('name_eng', styles.nameEng, 'Name')}
          {header('base', styles.base, 'ベース')}
          {header('recipe_count', styles.recipeCount, '必須')}
          {header('sweet_degree', styles.sweet, '甘さ')}
          {header('rate', styles.rate, '評価')}
          {header('strength', styles.strength, '度数')}
          {header('youtube', styles.youtube, '動画')}
        </tr>
      </thead>
      <tbody>
        {rows.map((c) => (
          <tr key={c.id} className={c.youtube ? styles.hasMovie : undefined}>
            <td className={styles.method}>
              <MethodIcon method={c.method} />
            </td>
            <td className={styles.name}>
              <Link to={cocktailPath(c.id)}>{c.name}</Link>
            </td>
            <td className={styles.nameEng}>
              {c.name_eng && <Link to={cocktailPath(c.id)}>{c.name_eng}</Link>}
            </td>
            <td className={styles.base}>
              <Link to={categoryPath(c.base.id)}>
                {c.base.name}
                <span className={styles.baseEng}>({c.base.name_eng})</span>
              </Link>
            </td>
            <td className={styles.recipeCount}>{c.recipe_count}</td>
            <td className={styles.sweet}>{sweetDegreeLabel(c.sweet_degree)}</td>
            <td className={c.rate ? `${styles.rate} ${styles.rateFilled}` : styles.rate}>{rateStars(c.rate)}</td>
            <td className={styles.strength}>{strengthLabel(c.strength)}</td>
            <td className={styles.youtube}>
              {c.youtube ? (
                <a href={youtubeWatchUrl(c.youtube)} target="_blank" rel="noopener">
                  <FontAwesomeIcon icon="youtube" label={`${c.name}の動画(YouTube)`} />
                </a>
              ) : (
                '-'
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
