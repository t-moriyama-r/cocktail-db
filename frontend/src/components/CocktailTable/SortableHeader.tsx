import styles from './CocktailTable.module.css'
import type { CocktailSort, CocktailSortKey } from './CocktailTable.types'

/**
 * 並べ替えられる列の見出し。押せることがキーボード・読み上げでも分かるよう、見出しの中をボタンにする。
 * いま並べ替えている列には、向きの印と aria-sort を付ける
 */
type Props = {
  sortKey: CocktailSortKey
  current: CocktailSort | null
  className: string
  onSort: (key: CocktailSortKey) => void
  children: string
}

export default function SortableHeader({ sortKey, current, className, onSort, children }: Props) {
  const isSorted = current?.key === sortKey
  const ariaSort = isSorted ? (current.isAsc ? 'ascending' : 'descending') : undefined
  return (
    <th className={className} aria-sort={ariaSort} scope="col">
      <button type="button" className={styles.sortButton} onClick={() => onSort(sortKey)}>
        {children}
        {isSorted && (
          <span className={styles.sortMark} aria-hidden="true">
            {current.isAsc ? '▲' : '▼'}
          </span>
        )}
      </button>
    </th>
  )
}
