import { useCallback, useState } from 'react'
import Affiliate from '../../components/Affiliate/Affiliate'
import styles from './CocktailDetail.module.css'
import type { CocktailIngredient } from './CocktailDetail.types'

type Props = {
  /** API が返した順(position の順)のまま並べる */
  ingredients: readonly CocktailIngredient[]
}

/**
 * カクテルの詳細の下に並べる、材料ごとの商品の欄(旧 Detail/CocktailDetail.vue の recipe_amazon)。
 * 材料ごとに、材料のノード(path の末尾)と、決めてあれば銘柄を商品の欄(#303)に渡す。
 * 旧は商品の欄が 1 つも出なくても見出し「使われている材料はこちら」を出していたが、新は 1 つも出ないときは見出しも出さない
 * (#110 の「旧との差分」)。
 */
export default function IngredientAffiliates({ ingredients }: Props) {
  const [shownPositions, setShownPositions] = useState<ReadonlySet<number>>(() => new Set())
  const report = useCallback((position: number, shown: boolean) => {
    setShownPositions((current) => {
      if (current.has(position) === shown) return current
      const next = new Set(current)
      if (shown) next.add(position)
      else next.delete(position)
      return next
    })
  }, [])
  const hasShown = ingredients.some((ingredient) => shownPositions.has(ingredient.position))

  return (
    <section className={hasShown ? styles.affiliates : undefined} aria-label={hasShown ? '使われている材料' : undefined}>
      {hasShown && <h2 className={styles.affiliatesHeading}>使われている材料はこちら</h2>}
      <div className={styles.affiliateList}>
        {ingredients.map((ingredient) => (
          <IngredientAffiliate key={ingredient.position} ingredient={ingredient} report={report} />
        ))}
      </div>
    </section>
  )
}

function IngredientAffiliate({
  ingredient,
  report,
}: {
  ingredient: CocktailIngredient
  report: (position: number, shown: boolean) => void
}) {
  const { position, path, brand } = ingredient
  const onShownChange = useCallback((shown: boolean) => report(position, shown), [report, position])
  const node = path.at(-1)
  if (node === undefined) return null
  return (
    <div className={styles.affiliateItem}>
      <Affiliate categoryId={node.id} brandId={brand?.id ?? null} onShownChange={onShownChange} />
    </div>
  )
}
