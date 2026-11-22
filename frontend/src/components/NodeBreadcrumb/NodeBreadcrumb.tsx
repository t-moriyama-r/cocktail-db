import { Fragment } from 'react'
import { Link } from 'react-router'
import type { components } from '../../api/schema'
import { categoryPath } from '../../utils/detailPaths'
import FontAwesomeIcon from '../CocktailTable/FontAwesomeIcon'
import styles from './NodeBreadcrumb.module.css'

type NodeRef = components['schemas']['NodeRefResource']

type Props = {
  /** パンくずに出すノード。根が先頭。開いているノード自身は入れない(呼ぶ側が API のパンくずから末尾を除いて渡す) */
  nodes: readonly NodeRef[]
}

/**
 * 詳細の画面のタイトルの下に出す、木の中の位置(旧 Detail/component/Breadcrumb.vue)。
 * 旧と同じく、コンパスのアイコンの後にノードの名前を「/」で区切って並べる。
 * 根(旧の種別)は旧では詳細ページが無くリンクにしていなかったので、新の根には詳細ページがあるが、旧と同じくリンクにしない(#105)。
 * 根の下の段はノードの詳細へのリンクにする。出すノードが無ければ(根の詳細)何も出さない。
 */
export default function NodeBreadcrumb({ nodes }: Props) {
  if (nodes.length === 0) {
    return null
  }

  return (
    <nav className={styles.breadcrumb} aria-label="パンくず">
      <FontAwesomeIcon icon="compass" label={null} />
      {nodes.map((node, index) => (
        <Fragment key={node.id}>
          {index > 0 && ' / '}
          {index === 0 ? node.name : <Link to={categoryPath(node.id)}>{node.name}</Link>}
        </Fragment>
      ))}
    </nav>
  )
}
