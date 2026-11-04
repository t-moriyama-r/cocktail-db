import { useCallback } from 'react'
import { useNavigate, useParams } from 'react-router'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import InlineApiView from '../components/ApiView/InlineApiView'
import { ADMIN_COCKTAILS_PATH, ADMIN_DRAFT_COCKTAILS_PATH, ADMIN_HOME_PATH } from '../components/AdminLayout/adminPaths'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import CocktailForm from '../features/admin-cocktails/CocktailForm'
import {
  cocktailFormValuesOf,
  EMPTY_COCKTAIL_FORM,
  type CocktailFormValues,
} from '../features/admin-cocktails/cocktailFormValues'

/** ベースを選ぶ木。検索フォームの API(#95)が、根から一番深い段までの木全体を返すので、それを使う(銘柄の所属と同じ) */
const fetchCategoryTree = () => api.GET('/search-form')

/** 技法・グラス・氷の選択肢(#291) */
const fetchFormOptions = () => api.GET('/admin/cocktail-form-options')

/**
 * 管理画面のカクテルの新規(/admin/cocktails/new)・編集(/admin/cocktails/:id)の画面(旧 `Admin/Super/Detail/CocktailEdit.vue`。#127)。
 *
 * 編集はカクテルを読み込んでからフォームを出し、無い ID なら 404 の画面を出す。
 * 保存できたら、旧と同じく、下書きなら下書きの一覧へ、公開なら公開済みの一覧へ戻る(旧は結果を待たずに移っていた。
 * docs/decisions/admin-screens.md の既定)。消せたら、旧と同じくメニューへ移る。
 */
export default function AdminCocktailEdit() {
  const { id } = useParams()
  return id === undefined ? <NewCocktail /> : <EditCocktail id={Number(id)} />
}

function NewCocktail() {
  usePageTitle('カクテルの新規')
  return <CocktailEditPage title="カクテルの新規" cocktailId={null} initialValues={EMPTY_COCKTAIL_FORM} currentImage={null} />
}

function EditCocktail({ id }: { id: number }) {
  usePageTitle('カクテルの編集')
  const request = useCallback(() => api.GET('/admin/cocktails/{cocktail}', { params: { path: { cocktail: id } } }), [id])
  const { state, retry } = useApi(request)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data: cocktail }) => (
        // 別のカクテルを開き直したとき(同じ名前の一覧のリンクなど)に、前のカクテルの入力を残さない
        <CocktailEditPage
          key={cocktail.id}
          title="カクテルの編集"
          cocktailId={cocktail.id}
          initialValues={cocktailFormValuesOf(cocktail)}
          currentImage={cocktail.image}
        />
      )}
    </ApiView>
  )
}

function CocktailEditPage({
  title,
  cocktailId,
  initialValues,
  currentImage,
}: {
  title: string
  cocktailId: number | null
  initialValues: CocktailFormValues
  currentImage: string | null
}) {
  const navigate = useNavigate()
  const tree = useApi(fetchCategoryTree)
  const options = useApi(fetchFormOptions)

  return (
    <main>
      <h1>{title}</h1>
      <InlineApiView state={tree.state} onRetry={tree.retry}>
        {({ categories }) => (
          <InlineApiView state={options.state} onRetry={options.retry}>
            {(formOptions) => (
              <CocktailForm
                cocktailId={cocktailId}
                initialValues={initialValues}
                currentImage={currentImage}
                categories={categories}
                options={formOptions}
                onSaved={(isDraft) => void navigate(isDraft ? ADMIN_DRAFT_COCKTAILS_PATH : ADMIN_COCKTAILS_PATH)}
                onDeleted={() => void navigate(ADMIN_HOME_PATH)}
              />
            )}
          </InlineApiView>
        )}
      </InlineApiView>
    </main>
  )
}
