import { useId } from 'react'
import { Link } from 'react-router'
import type { components } from '../../api/schema'
import { adminCocktailEditPath } from '../../components/AdminLayout/adminPaths'
import styles from './CocktailForm.module.css'

type Duplicate = components['schemas']['CocktailDuplicateResource']

type Props = {
  /** 名前・英名が同じカクテル(材料の近い順。API の並びのまま) */
  sameName: readonly Duplicate[]
  /** 材料の組が同じカクテル(ID の順) */
  sameRecipe: readonly Duplicate[]
  /** 技法の ID から名前を引く(フォームの選択肢の技法) */
  methodNameOf: (methodId: number) => string | undefined
}

/**
 * 編集画面の左に出す、同じ名前・同じレシピのカクテル(旧の重複チェック。旧 `AutoInputDub.vue`。#129)。
 *
 * 旧と同じく、1 件ごとに技法・名前(そのカクテルの編集画面へのリンク)・材料の行(ノード・銘柄・量)・説明・補足を出す。
 * 同じレシピのカクテル(旧に無い。docs/decisions/admin-screens.md の決まったこと 3)は、名前の一覧の下に見出しを分けて出す
 * (docs/decisions/admin-cocktails.md)。どちらも 0 件の一覧は見出しごと出さない。
 */
export default function CocktailDuplicates({ sameName, sameRecipe, methodNameOf }: Props) {
  return (
    <aside className={styles.duplicates} aria-label="同じ名前・同じレシピのカクテル">
      <DuplicateList title="同じ名前のカクテル" cocktails={sameName} methodNameOf={methodNameOf} />
      <DuplicateList title="同じレシピのカクテル" cocktails={sameRecipe} methodNameOf={methodNameOf} />
    </aside>
  )
}

function DuplicateList({
  title,
  cocktails,
  methodNameOf,
}: {
  title: string
  cocktails: readonly Duplicate[]
  methodNameOf: Props['methodNameOf']
}) {
  const headingId = useId()
  if (cocktails.length === 0) {
    return null
  }
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} className={styles.duplicatesHeading}>
        {title}
      </h2>
      <ul className={styles.duplicateList}>
        {cocktails.map((cocktail) => (
          <li key={cocktail.id} className={styles.duplicate}>
            <p className={styles.duplicateName}>
              {methodNameOf(cocktail.method_id) !== undefined && (
                <span className={styles.duplicateMethod}>{methodNameOf(cocktail.method_id)}</span>
              )}
              <Link to={adminCocktailEditPath(cocktail.id)}>{cocktail.name}</Link>
            </p>
            <table className={styles.duplicateIngredients}>
              <tbody>
                {cocktail.ingredients.map((ingredient, index) => (
                  // 同じノード・銘柄の行が 2 つあることがあるので、並びの位置で区別する
                  <tr key={index}>
                    <td>{ingredient.category_name}</td>
                    <td>{ingredient.brand_name}</td>
                    <td className={styles.duplicateAmount}>{ingredient.amount}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {cocktail.description !== null && cocktail.description !== '' && <p className={styles.duplicateText}>{cocktail.description}</p>}
            {cocktail.note !== null && cocktail.note !== '' && <p className={styles.duplicateText}>{cocktail.note}</p>}
          </li>
        ))}
      </ul>
    </section>
  )
}
