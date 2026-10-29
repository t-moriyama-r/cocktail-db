import styles from './CocktailTable.module.css'

/**
 * 並べ替えられる列の見出し。押せることがキーボード・読み上げでも分かるよう、見出しの中をボタンにする。
 * いま並べ替えている列には、向きの印と aria-sort を付ける。
 * カクテルの一覧の表と銘柄の一覧の表(BrandTable)で使うので、列の名前の型は使う表が決める
 */
type Props<K extends string> = {
  sortKey: K
  current: { key: K; isAsc: boolean } | null
  className: string
  onSort: (key: K) => void
  children: string
}

export default function SortableHeader<K extends string>({ sortKey, current, className, onSort, children }: Props<K>) {
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
