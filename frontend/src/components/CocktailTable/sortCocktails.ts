import type { CocktailSort, CocktailSortKey, CocktailTableRow } from './CocktailTable.types'
import { toComparableKana } from '../../utils/toComparableKana'

/**
 * カクテルの一覧を、表の見出しで選んだ列で並べ替える(旧 Results/Tables/Cocktails.vue の sortedList と同じ並び)。
 *
 * 甘さ・評価・動画は「値が無い」ものが比べようのない行なので、向きによらずいつも最後に置く。
 * 動画のある行どうしは、動画の新しさを見たいので更新日時で比べる(旧も updated_at で比べていた)。
 * 名前・ベースは、カタカナとひらがな、全角と半角の英数字の書き分けで並びが割れないよう、そろえてから比べる。
 * 比べ方は旧と同じく文字コードの大小で、localeCompare は使わない(旧と並びが変わるため)。
 * Array.prototype.sort は安定なので、同じ値の行は渡した順のまま残る。
 */
export function sortCocktails(cocktails: readonly CocktailTableRow[], sort: CocktailSort | null): CocktailTableRow[] {
  const list = [...cocktails]
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

/**
 * 列ごとの比べる値。null を返した行は、向きによらず最後に置く
 */
const SORT_VALUE: Record<CocktailSortKey, (cocktail: CocktailTableRow) => string | number | null> = {
  method: (c) => c.method.id,
  name: (c) => toComparableKana(c.name),
  // 英名は大文字と小文字が混ざると並びが割れるので、小文字にそろえる(旧と同じ)。英名の無い行は旧のデータに無い
  name_eng: (c) => (c.name_eng ?? '').toLowerCase(),
  base: (c) => toComparableKana(c.base.name),
  recipe_count: (c) => c.recipe_count,
  sweet_degree: (c) => c.sweet_degree,
  rate: (c) => c.rate,
  strength: (c) => c.strength,
  // 動画のある行の更新日時が無いことは旧のデータに無い。比べられるよう、いちばん古いものとして扱う
  youtube: (c) => (c.youtube ? updatedAtTime(c.updated_at) : null),
}

// 日時として読めない文字列(NaN)は比べると並びが安定しないので、無い場合と同じくいちばん古いものとして扱う
function updatedAtTime(updatedAt: string | null | undefined): number {
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
