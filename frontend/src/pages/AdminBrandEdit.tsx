import { useCallback } from 'react'
import { useNavigate, useParams } from 'react-router'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import InlineApiView from '../components/ApiView/InlineApiView'
import { ADMIN_BRANDS_PATH } from '../components/AdminLayout/adminPaths'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import BrandForm from '../features/admin-brands/BrandForm'
import { brandFormValuesOf, EMPTY_BRAND_FORM, type BrandFormValues } from '../features/admin-brands/brandFormValues'

/** 所属を選ぶ木。検索フォームの API(#95)が、根から一番深い段までの木全体を返すので、それを使う */
const fetchCategoryTree = () => api.GET('/search-form')

/**
 * 管理画面の銘柄の新規(/admin/brands/new)・編集(/admin/brands/:id)の画面(旧 `Detail/BrandEdit.vue`。#64)。
 *
 * 編集は銘柄を読み込んでからフォームを出し、無い ID なら 404 の画面を出す。保存できたら一覧へ戻る
 * (旧は保存の結果を待たずにメニューへ戻っていた。#64 の追記)。
 */
export default function AdminBrandEdit() {
  const { id } = useParams()
  return id === undefined ? <NewBrand /> : <EditBrand id={Number(id)} />
}

function NewBrand() {
  usePageTitle('銘柄の新規')
  return <BrandEditPage title="銘柄の新規" brandId={null} initialValues={EMPTY_BRAND_FORM} currentImage={null} />
}

function EditBrand({ id }: { id: number }) {
  usePageTitle('銘柄の編集')
  const request = useCallback(() => api.GET('/admin/brands/{brand}', { params: { path: { brand: id } } }), [id])
  const { state, retry } = useApi(request)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data: brand }) => (
        // 別の銘柄を開き直したときに、前の銘柄の入力を残さない
        <BrandEditPage
          key={brand.id}
          title="銘柄の編集"
          brandId={brand.id}
          initialValues={brandFormValuesOf(brand)}
          currentImage={brand.image}
        />
      )}
    </ApiView>
  )
}

function BrandEditPage({
  title,
  brandId,
  initialValues,
  currentImage,
}: {
  title: string
  brandId: number | null
  initialValues: BrandFormValues
  currentImage: string | null
}) {
  const navigate = useNavigate()
  const { state, retry } = useApi(fetchCategoryTree)

  return (
    <main>
      <h1>{title}</h1>
      <InlineApiView state={state} onRetry={retry}>
        {({ categories }) => (
          <BrandForm
            brandId={brandId}
            initialValues={initialValues}
            currentImage={currentImage}
            categories={categories}
            onSaved={() => void navigate(ADMIN_BRANDS_PATH)}
          />
        )}
      </InlineApiView>
    </main>
  )
}
