import { Link } from 'react-router'
import styles from './Pager.module.css'
import { pagerItems } from './pagerItems'

type Props = {
  /** いまのページ(1 から)。最後のページより後でもよい */
  current: number
  last: number
  /** そのページの URL */
  hrefOf: (page: number) => string
  /** 部品の名前(読み上げ用) */
  label: string
}

/**
 * ページを選ぶ部品(#103)。「前へ」・ページの番号・「次へ」をリンクで並べる。リンクなので、戻るボタンで前のページに戻り、
 * 別のタブでも開ける。1 ページしか無いときは何も出さない(旧はページを分けず、ページを選ぶ部品が無かったのと同じ見た目)。
 * 並べる番号の決め方は pagerItems
 */
export default function Pager({ current, last, hrefOf, label }: Props) {
  const items = pagerItems(current, last)
  if (items.length === 0) return null

  return (
    <nav className={styles.pager} aria-label={label}>
      <ul className={styles.list}>
        {current > 1 && (
          <li>
            <Link className={styles.item} to={hrefOf(Math.min(current - 1, last))}>
              前へ
            </Link>
          </li>
        )}
        {items.map((item, index) =>
          item.type === 'gap' ? (
            <li key={`gap-${index}`} className={styles.gap} aria-hidden="true">
              …
            </li>
          ) : (
            <li key={item.page}>
              {item.page === current ? (
                <span className={`${styles.item} ${styles.current}`} aria-current="page">
                  {item.page}
                </span>
              ) : (
                <Link className={styles.item} to={hrefOf(item.page)} aria-label={`${item.page} ページ目`}>
                  {item.page}
                </Link>
              )}
            </li>
          ),
        )}
        {current < last && (
          <li>
            <Link className={styles.item} to={hrefOf(current + 1)}>
              次へ
            </Link>
          </li>
        )}
      </ul>
    </nav>
  )
}
