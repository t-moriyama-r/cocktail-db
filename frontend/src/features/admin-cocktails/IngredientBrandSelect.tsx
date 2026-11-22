import { useCallback } from 'react'
import type { components } from '../../api/schema'
import { useApi, type ApiState } from '../../api/useApi'
import { fetchCategoryBrands } from '../admin-paraphrases/adminParaphraseApi'
import { SelectField, type SelectOption } from '../../components/AdminForm/AdminField'
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
    return <SelectField label="銘柄" errors={errors} value="" options={[]} empty="none" disabled onChange={() => undefined} />
  }
  return <NodeBrandSelect categoryId={categoryId} brandId={brandId} errors={errors} onSelect={onSelect} />
}

function NodeBrandSelect({ categoryId, brandId, errors, onSelect }: Props & { categoryId: number }) {
  const request = useCallback(() => fetchCategoryBrands(categoryId), [categoryId])
  const { state, retry } = useApi(request)
  const brands = state.kind === 'success' ? state.data.data.brands : null
  const value = brandId === null ? '' : String(brandId)
  const options = (brands ?? []).map((brand) => ({ value: String(brand.id), label: brandLabel(brand) }))

  const change = (selected: string) => {
    const brand = brands?.find((candidate) => String(candidate.id) === selected)
    // 所属ノードはパンくずの末尾
    onSelect(brand === undefined ? null : { id: brand.id, categoryId: brand.path[brand.path.length - 1].id })
  }

  return (
    <>
      <SelectField
        label="銘柄"
        errors={errors}
        value={value}
        options={withCurrent(options, value, state)}
        empty="none"
        disabled={brands === null}
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
 * 選んでいる銘柄が選択肢に無ければ、それを表す選択肢を先頭に足す(選択の欄は、選択肢に無い値を出せないため)。
 * 読み込みの間は読み込み中と出し、読み込んだ後も無ければ、行のノードの配下に無い銘柄(移した旧データの、ノードと銘柄の
 * 所属が合わない行など)とわかるように出す
 */
function withCurrent(options: SelectOption[], value: string, state: ApiState<unknown>): SelectOption[] {
  if (value === '' || options.some((option) => option.value === value)) return options
  const label =
    state.kind === 'loading' ? '(読み込み中)' : state.kind === 'success' ? '(このノードの配下に無い銘柄)' : '(読み込めなかった銘柄)'
  return [{ value, label }, ...options]
}
