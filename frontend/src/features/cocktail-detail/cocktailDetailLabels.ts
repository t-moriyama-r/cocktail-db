import type { CocktailDetailData } from './CocktailDetail.types'

/**
 * 見出しの「〈名前〉(〈英名〉)」(旧 Detail/CocktailDetail.vue の title)。
 * 旧は英名が無いと「〈名前〉()」と出していた(Vue の {{ }} は null を空の文字にする)ので、それに合わせる。
 * 旧の本番のデータには英名の無いカクテルが無く、画面で出たことは無い
 */
export function cocktailTitle({ name, name_eng }: Pick<CocktailDetailData, 'name' | 'name_eng'>): string {
  return `${name}(${name_eng ?? ''})`
}

/**
 * おすすめ度を ★ の数で出す。評価の無いカクテル(null)と 0 は「未評価」(旧の `"★".repeat(rate) || "未評価"`)。
 * 一覧の表(rateStars)は同じ場合に「-」を出していて、旧でも詳細と一覧で出し方が違う
 */
export function rateLabel(rate: number | null): string {
  return rate ? '★'.repeat(rate) : '未評価'
}

/** 度数は丸めずに「〈値〉%」で出す(旧の `{{strength}}%`)。一覧の表(strengthLabel)は小数 1 桁にそろえている */
export function strengthPercent(strength: number): string {
  return `${strength}%`
}

/** グラスの欄の「〈氷の名前〉〈グラスの名前〉」。氷が無ければグラスの名前だけ(旧と同じく間に空白を入れない) */
export function glassLabel(
  glass: NonNullable<CocktailDetailData['glass']>,
  ice: CocktailDetailData['ice'],
): string {
  return `${ice?.name ?? ''}${glass.name}`
}
