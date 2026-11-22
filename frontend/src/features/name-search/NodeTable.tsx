import { Fragment } from 'react'
import { Link } from 'react-router'
import type { components } from '../../api/schema'
import { categoryPath } from '../../utils/detailPaths'
import styles from './NodeTable.module.css'
import { parentNodes } from './parentNodes'

type NameSearchNode = components['schemas']['NameSearchNodeResource']

/**
 * 名前検索の結果のノードの表(旧 Results/children の「カテゴリー検索結果」の表)。
 * 列は 名前・Name(どちらもノードの詳細へ)・親。旧の「親カテゴリ」は、任意の深さの木に合わせ、
 * 根を除いた祖先をいちばん上の段とすぐ上の親の「ラム / ホワイトラム」の形で出す(docs/decisions/public-detail.md の決まったこと 3)。
 * 渡した順(API の ID の順)のまま出す
 */
type Props = {
  nodes: readonly NameSearchNode[]
  /** 表の名前(読み上げに使う)。画面に出す見出しは表の外に置く */
  label: string
}

export default function NodeTable({ nodes, label }: Props) {
  return (
    <table className={styles.table} aria-label={label}>
      <thead>
        <tr>
          <th className={styles.name}>名前</th>
          <th className={styles.nameEng}>Name</th>
          <th className={styles.parent}>親</th>
        </tr>
      </thead>
      <tbody>
        {nodes.map((n) => (
          <tr key={n.id}>
            <td className={styles.name}>
              <Link to={categoryPath(n.id)}>{n.name}</Link>
            </td>
            <td className={styles.nameEng}>{n.name_eng && <Link to={categoryPath(n.id)}>{n.name_eng}</Link>}</td>
            <td className={styles.parent}>
              {parentNodes(n.path).map((p, i) => (
                <Fragment key={p.id}>
                  {i > 0 && ' / '}
                  <Link to={categoryPath(p.id)}>{p.name}</Link>
                </Fragment>
              ))}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
