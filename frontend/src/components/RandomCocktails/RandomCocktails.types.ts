/**
 * ランダムなカクテルの一覧の起点。どれを起点にするかで、呼ぶ API と見出しが変わる(docs/decisions/related-cocktails.md)。
 *
 * - cocktail: カクテルの詳細の「材料が似たカクテル」(GET /api/cocktails/{id}/related)
 * - base: カクテルの詳細の「同じベースのカクテル」(GET /api/cocktails/random の base_id)
 * - category・brand・method・glass: ノード・銘柄の詳細と、用語の技法・グラスの「これを使ったカクテル」
 *   (GET /api/cocktails/random の category_id・brand_id・method_id・glass_id)
 */
export type RandomCocktailsOrigin = {
  kind: 'cocktail' | 'category' | 'brand' | 'base' | 'method' | 'glass'
  id: number
}
