import { Fragment } from 'react'
import { Link } from 'react-router'
import { affiliationNodes } from '../../components/BrandTable/affiliationNodes'
import styles from './CocktailDetail.module.css'
import type { CocktailIngredient } from './CocktailDetail.types'
import { brandPath, categoryPath } from '../../utils/detailPaths'

type Props = {
  /** API が返した順(position の順)のまま出す */
  ingredients: readonly CocktailIngredient[]
}

/**
 * カクテルの詳細の材料の表(旧 Detail/CocktailDetail.vue の #cocktail_recipe)。列は「材料」「Material」「分量」。
 * 「Material」の列は、旧と同じく幅 600px 未満で出さない。分量は保存した文字のまま出す。
 */
export default function IngredientTable({ ingredients }: Props) {
  return (
    <table className={styles.ingredients} aria-label="材料">
      <thead>
        <tr>
          <th>材料</th>
          <th className={styles.material}>Material</th>
          <th>分量</th>
        </tr>
      </thead>
      <tbody>
        {ingredients.map((ingredient) => (
          <tr key={ingredient.position}>
            <td>
              <IngredientName ingredient={ingredient} field="name" />
              {/* 旧と同じく、任意の印は日本語の列にだけ付ける */}
              {ingredient.is_optional && '(任意)'}
            </td>
            <td className={styles.material}>
              <IngredientName ingredient={ingredient} field="name_eng" />
            </td>
            <td className={styles.amount}>{ingredient.amount}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

/**
 * 材料のノードを「ラム / ホワイトラム (バカルディ)」の形で出す。ノードはいちばん上の段と材料のノードだけを選び、
 * 間のノードは省く。各段はノードの詳細へ、銘柄は銘柄の詳細へリンクする(docs/decisions/public-detail.md の決まったこと 3)。
 * 「材料」の列は名前、「Material」の列は同じ形で英名を出す
 */
function IngredientName({ ingredient, field }: { ingredient: CocktailIngredient; field: 'name' | 'name_eng' }) {
  const { brand } = ingredient
  return (
    <>
      {affiliationNodes(ingredient.path).map((node, index) => (
        <Fragment key={node.id}>
          {index > 0 && ' / '}
          <Link to={categoryPath(node.id)}>{node[field]}</Link>
        </Fragment>
      ))}
      {brand && (
        <>
          {' ('}
          <Link to={brandPath(brand.id)}>{brand[field]}</Link>
          {')'}
        </>
      )}
    </>
  )
}
