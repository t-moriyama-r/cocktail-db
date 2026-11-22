import type { components } from '../../api/schema'

/** カクテルの詳細の画面に出すカクテル。API の CocktailDetailResource をそのまま使う */
export type CocktailDetailData = components['schemas']['CocktailDetailResource']

/** 材料の 1 つ。API の CocktailIngredientResource をそのまま使う */
export type CocktailIngredient = components['schemas']['CocktailIngredientResource']
