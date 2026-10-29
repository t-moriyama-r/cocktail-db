import type { BrandSort, BrandSortKey, BrandTableRow } from './BrandTable.types'
import { affiliationNodes } from './affiliationNodes'
import { toComparableKana } from '../../utils/toComparableKana'

/**
 * 見出しを押したときの次の並び。同じ列をもう一度押すと向きを逆にし、別の列なら昇順から始める
 * (旧 Results/Tables/Brands.vue の sortBy。旧の銘柄の表は、どの列も初めは昇順だった)
 */
export function nextBrandSort(current: BrandSort | null, key: BrandSortKey): BrandSort {
  return { key, isAsc: current?.key === key ? !current.isAsc : true }
}

/**
 * 銘柄の一覧を、表の見出しで選んだ列で並べ替える(旧 Results/Tables/Brands.vue の sortedList)。
 *
 * 名前・所属は、カタカナとひらがな、全角と半角の英数字の書き分けで並びが割れないよう、そろえてから比べる。
 * 英名は大文字と小文字をそろえる。比べ方は旧と同じく文字コードの大小で、localeCompare は使わない。
 * 所属は、旧の「親カテゴリ」の列と同じく、いちばん上の段の名前で比べる。
 * 度数・動画は「値が無い」ものが比べようのない行なので、向きによらずいつも最後に置く。
 * 動画のある行どうしは更新日時で比べる(旧も動画の更新日時で比べるつもりで書かれていた)。
 * Array.prototype.sort は安定なので、同じ値の行は渡した順のまま残る。
 */
export function sortBrands(brands: readonly BrandTableRow[], sort: BrandSort | null): BrandTableRow[] {
  const list = [...brands]
  if (!sort) {
    return list
  }
  const direction = sort.isAsc ? 1 : -1
  const keyOf = SORT_VALUE[sort.key]
  return list.sort((a, b) => {
    const aValue = keyOf(a)
    const bValue = keyOf(b)
    if (aValue === null || bValue === null) {
      return nullsLast(aValue, bValue)
    }
    return compareValues(aValue, bValue) * direction
  })
}

/** 列ごとの比べる値。null を返した行は、向きによらず最後に置く */
const SORT_VALUE: Record<BrandSortKey, (brand: BrandTableRow) => string | number | null> = {
  name: (b) => toComparableKana(b.name),
  name_eng: (b) => b.name_eng.toLowerCase(),
  affiliation: (b) => toComparableKana(affiliationNodes(b.path)[0]?.name ?? ''),
  strength: (b) => b.strength,
  youtube: (b) => (b.youtube ? updatedAtTime(b.updated_at) : null),
}

// 日時として読めない・無い更新日時は、比べると並びが安定しないので、いちばん古いものとして扱う
function updatedAtTime(updatedAt: string | null): number {
  const time = updatedAt ? Date.parse(updatedAt) : Number.NaN
  return Number.isNaN(time) ? Number.NEGATIVE_INFINITY : time
}

function nullsLast(a: unknown, b: unknown): number {
  if (a === null && b === null) return 0
  return a === null ? 1 : -1
}

function compareValues(a: string | number, b: string | number): number {
  if (a === b) return 0
  return a > b ? 1 : -1
}
