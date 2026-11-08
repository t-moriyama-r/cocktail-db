import { useCallback } from 'react'
import { api } from '../../api/client'
import type { components } from '../../api/schema'
import { useApi, type ApiState } from '../../api/useApi'
import { Field } from './CocktailFormFields'
import formStyles from './CocktailForm.module.css'
import styles from './IngredientRows.module.css'

type Brand = components['schemas']['BrandSummaryResource']

type Props = {
  /** 行のノード。まだ選んでいなければ null */
  categoryId: number | null
  brandId: number | null
  errors: readonly string[] | undefined
  /** 銘柄を選んだ。空の選択肢なら null。categoryId は銘柄の所属ノード */
  onSelect: (brand: { id: number; categoryId: number } | null) => void
}

/**
 * 材料の行の銘柄の欄。選択肢は、行のノードとその配下すべての銘柄(旧 RecipeSelect.vue は、選んだカテゴリかミドルの銘柄)。
 * ノードを選んでいない行では、選べない空の欄にする(旧は頭文字の欄で全部の銘柄から絞れたが、新はノードを木で選んでから選ぶ)。
 */
export default function IngredientBrandSelect({ categoryId, brandId, errors, onSelect }: Props) {
  if (categoryId === null) {
    return <BrandField brandId={null} options={[]} disabled errors={errors} onChange={() => undefined} />
  }
  return <NodeBrandSelect categoryId={categoryId} brandId={brandId} errors={errors} onSelect={onSelect} />
}

function NodeBrandSelect({ categoryId, brandId, errors, onSelect }: Props & { categoryId: number }) {
  // ノードの詳細(GET /api/categories/{id})が、そのノードと配下すべての銘柄を、所属までのパンくずつきで返す
  const request = useCallback(() => api.GET('/categories/{category}', { params: { path: { category: categoryId } } }), [categoryId])
  const { state, retry } = useApi(request)
  const brands = state.kind === 'success' ? state.data.data.brands : null

  const change = (value: string) => {
    const brand = brands?.find((candidate) => String(candidate.id) === value)
    if (brand === undefined) {
      onSelect(null)
      return
    }
    // 所属ノードはパンくずの末尾。パンくずが無いことは無いが、無ければ今のノードのままにする
    onSelect({ id: brand.id, categoryId: brand.path.at(-1)?.id ?? categoryId })
  }

  return (
    <>
      <BrandField
        brandId={brandId}
        options={brands === null ? [] : brands.map((brand) => ({ value: String(brand.id), label: brandLabel(brand) }))}
        fallbackLabel={missingBrandLabel(state)}
        disabled={brands === null}
        errors={errors}
        onChange={change}
      />
      {(state.kind === 'http-error' || state.kind === 'network-error' || state.kind === 'not-found') && (
        <p className={styles.brandFailure}>
          銘柄を読み込めませんでした。
          <button className={styles.button} type="button" onClick={retry}>
            銘柄を読み込み直す
          </button>
        </p>
      )}
    </>
  )
}

/** 銘柄の選択肢の文字。旧と同じく「名前(英名)」 */
const brandLabel = (brand: Brand) => `${brand.name}(${brand.name_eng})`

/**
 * 選んでいる銘柄が選択肢に無いときに出す文字(選択の欄は、選択肢に無い値を出せないため)。読み込みの間は読み込み中と出し、
 * 読み込んだ後も無ければ、行のノードの配下に無い銘柄(移した旧データの、ノードと銘柄の所属が合わない行など)とわかるように出す
 */
const missingBrandLabel = (state: ApiState<unknown>) =>
  state.kind === 'loading' ? '(読み込み中)' : state.kind === 'success' ? '(このノードの配下に無い銘柄)' : '(読み込めなかった銘柄)'

function BrandField({
  brandId,
  options,
  fallbackLabel = '',
  disabled,
  errors,
  onChange,
}: {
  brandId: number | null
  options: readonly { value: string; label: string }[]
  fallbackLabel?: string
  disabled: boolean
  errors: readonly string[] | undefined
  onChange: (value: string) => void
}) {
  const value = brandId === null ? '' : String(brandId)
  const known = value === '' || options.some((option) => option.value === value)
  return (
    <Field label="銘柄" errors={errors}>
      {(props) => (
        <select
          {...props}
          className={formStyles.input}
          value={value}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="" />
          {!known && <option value={value}>{fallbackLabel}</option>}
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  )
}
