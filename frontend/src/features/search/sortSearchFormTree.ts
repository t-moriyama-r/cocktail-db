import { toComparableKana } from '../../utils/toComparableKana'

/** 並べ直しに要る最小の形。API の SearchFormNodeResource も、木の部品の CategoryTreeNode も当てはまる */
type NamedTreeNode<T> = { name: string; children: readonly T[] }

/**
 * 検索フォームの木(GET /api/search-form の categories)を、旧の検索フォームと同じ並びにする(#400)。
 *
 * 旧(form.vue の kind_and_categories_aiu)は、種別ごとにカテゴリを名前の順に並べ直していた。
 * 比べ方も旧に合わせ、全角の英数字を半角に・カタカナをひらがなにそろえた名前を、文字コードの順で比べる
 * (localeCompare は使わない。大文字と小文字はそろえない)。そろえた名前が同じなら API の順のまま。
 *
 * - 根(旧の種別)は API の順(sort_order。移行で旧の ID の順)のまま
 * - 根のすぐ下(旧のカテゴリ)だけを並べ直す
 * - その下(旧のミドルと、それより深い段)は、旧が並べ直さずに BE から来た順で出していたので、API の順のまま
 *
 * 受け取った木は書き換えず、並べ直した新しい木を返す(ほかの画面は API の順で使うため)。
 */
export function sortSearchFormTree<T extends NamedTreeNode<T>>(roots: readonly T[]): T[] {
  return roots.map((root) => ({ ...root, children: sortByComparableName(root.children) }))
}

function sortByComparableName<T extends { name: string }>(nodes: readonly T[]): T[] {
  // Array.prototype.sort は安定なので、そろえた名前が同じノードは API の順に残る
  return [...nodes].sort((a, b) => {
    const left = toComparableKana(a.name)
    const right = toComparableKana(b.name)
    return left === right ? 0 : left > right ? 1 : -1
  })
}
