import { useState } from 'react'
import { Link } from 'react-router'
import styles from './BrandTable.module.css'
import type { BrandSort, BrandSortKey, BrandTableNode, BrandTableRow } from './BrandTable.types'
import { affiliationNodes } from './affiliationNodes'
import { nextBrandSort, sortBrands } from './sortBrands'
import FontAwesomeIcon from '../CocktailTable/FontAwesomeIcon'
import SortableHeader from '../CocktailTable/SortableHeader'
import { brandPath, categoryPath } from '../../utils/detailPaths'
import { youtubeWatchUrl } from '../../utils/youtubeWatchUrl'

/**
 * 銘柄の一覧の表(旧 Results/Tables/Brands.vue)。名前検索の結果・銘柄の詳細・カテゴリの詳細で使う。
 *
 * 旧の「親カテゴリ」「ミドルカテゴリ」の 2 列は、任意の深さの木に合わせて「所属」の 1 列にし、
 * いちばん上の段と所属するノードを「ラム / ホワイトラム」の形で出す(docs/decisions/public-detail.md の決まったこと 3)。
 * 並べ替えは旧と同じく、見出しを押して表の中で行う。最初は渡した順のまま出す。
 */
type Props = {
  brands: readonly BrandTableRow[]
  /** 表の名前(読み上げに使う)。画面に出す見出しは表の外に置く */
  label: string
}

export default function BrandTable({ brands, label }: Props) {
  const [sort, setSort] = useState<BrandSort | null>(null)
  const rows = sortBrands(brands, sort)

  const onSort = (key: BrandSortKey) => setSort(nextBrandSort(sort, key))

  const header = (key: BrandSortKey, className: string, text: string) => (
    <SortableHeader key={key} sortKey={key} current={sort} className={className} onSort={onSort}>
      {text}
    </SortableHeader>
  )

  return (
    <table className={styles.table} aria-label={label}>
      <thead>
        <tr>
          {header('name', styles.name, '名前')}
          {header('name_eng', styles.nameEng, 'Name')}
          {header('affiliation', styles.affiliation, '所属')}
          {header('strength', styles.strength, '度数')}
          {header('youtube', styles.youtube, '動画')}
        </tr>
      </thead>
      <tbody>
        {rows.map((b) => (
          <tr key={b.id} className={b.youtube ? styles.hasMovie : undefined}>
            <td className={styles.name}>
              <Link to={brandPath(b.id)}>{b.name}</Link>
            </td>
            <td className={styles.nameEng}>{b.name_eng && <Link to={brandPath(b.id)}>{b.name_eng}</Link>}</td>
            <td className={styles.affiliation}>
              <Affiliation path={b.path} />
            </td>
            <td className={styles.strength}>{strengthText(b.strength)}</td>
            <td className={styles.youtube}>
              {b.youtube ? (
                <a href={youtubeWatchUrl(b.youtube)} target="_blank" rel="noopener">
                  <FontAwesomeIcon icon="youtube" label={`${b.name}の動画(YouTube)`} />
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

/**
 * 所属の段を「ラム(Rum) / ホワイトラム(White Rum)」の形で、それぞれノードの詳細へのリンクにして出す。
 * 旧の 2 列の出し方に合わせ、いちばん上の段は英名をいつも出し、所属するノードの段は英名を 960px 未満で、段ごと 700px 未満で隠す
 */
function Affiliation({ path }: { path: readonly BrandTableNode[] }) {
  const [top, last] = affiliationNodes(path)
  if (!top) {
    return null
  }
  return (
    <>
      <Link to={categoryPath(top.id)}>
        {top.name}
        {top.name_eng && `(${top.name_eng})`}
      </Link>
      {last && (
        <span className={styles.lastNode}>
          {' / '}
          <Link to={categoryPath(last.id)}>
            {last.name}
            {last.name_eng && <span className={styles.lastNodeEng}>({last.name_eng})</span>}
          </Link>
        </span>
      )}
    </>
  )
}

/** 度数は旧と同じく値のまま出し、無い(と 0 の)ときは空にする(旧は `volume || ""`) */
function strengthText(strength: number | null): string {
  return strength ? String(strength) : ''
}
