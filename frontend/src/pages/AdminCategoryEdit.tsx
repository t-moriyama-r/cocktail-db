import { useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '../api/client'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import InlineApiView from '../components/ApiView/InlineApiView'
import { ADMIN_CATEGORIES_PATH, adminBrandEditPath, adminCategoryEditPath } from '../components/AdminLayout/adminPaths'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import CategoryForm from '../features/admin-categories/CategoryForm'
import type { CategorySaveWarning } from '../features/admin-categories/categoryApi'
import {
  categoryFormValuesOf,
  categoryKeptValuesOf,
  EMPTY_CATEGORY_FORM,
  NEW_CATEGORY_KEPT,
} from '../features/admin-categories/categoryFormValues'
import { savedNotice } from '../features/admin-categories/categoryTreeNotice'
import styles from './AdminCategoryEdit.module.css'

type CategoryResource = components['schemas']['CategoryResource']
type NodeSummary = CategoryResource['children'][number]

/** 親を選ぶ木。カテゴリ木の画面(#123)と同じ管理 API の木を使う */
const fetchCategoryTree = () => api.GET('/admin/categories')

/**
 * 管理画面のノードの新規(/admin/categories/new)・編集(/admin/categories/:id)の画面(旧 `Detail/CategoryEdit.vue`・`MiddleEdit.vue`。#308)。
 *
 * 編集はノードを読み込んでからフォームを出し、無い ID なら 404 の画面を出す。フォームの下に、子のノードと直下の銘柄の一覧を出す
 * (旧の「登録されているミドルカテゴリ一覧」「登録されている銘柄の一覧」)。保存できたらカテゴリ木の画面へ戻り、保存したことを出す。
 */
export default function AdminCategoryEdit() {
  const { id } = useParams()
  return id === undefined ? <NewCategory /> : <EditCategory id={Number(id)} />
}

/** 保存できたら、カテゴリ木の画面へ戻り、保存したこと(と保存の後の警告)を出す */
function useBackToTree() {
  const navigate = useNavigate()
  return (name: string, warnings: readonly CategorySaveWarning[]) =>
    void navigate(ADMIN_CATEGORIES_PATH, { state: savedNotice(name, warnings) })
}

function NewCategory() {
  usePageTitle('ノードの新規')
  const { state, retry } = useApi(fetchCategoryTree)
  const backToTree = useBackToTree()

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>ノードの新規</h1>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) => (
          <CategoryForm
            nodeId={null}
            initialValues={EMPTY_CATEGORY_FORM}
            kept={NEW_CATEGORY_KEPT}
            currentImage={null}
            parent={{ kind: 'select', tree: data }}
            onSaved={backToTree}
          />
        )}
      </InlineApiView>
    </main>
  )
}

function EditCategory({ id }: { id: number }) {
  usePageTitle('ノードの編集')
  const request = useCallback(() => api.GET('/admin/categories/{category}', { params: { path: { category: id } } }), [id])
  const { state, retry } = useApi(request)
  const backToTree = useBackToTree()

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data: node }) => (
        <main className={styles.page}>
          <h1 className={styles.title}>ノードの編集</h1>
          {/* 子のノードの一覧から別のノードへ移ったときに、前のノードの入力を残さない */}
          <CategoryForm
            key={node.id}
            nodeId={node.id}
            initialValues={categoryFormValuesOf(node)}
            kept={categoryKeptValuesOf(node)}
            currentImage={node.image}
            parent={{ kind: 'fixed', path: node.path.map((step) => step.name) }}
            onSaved={backToTree}
          />
          <NodeList title="登録されている子のノードの一覧" items={node.children} empty="子のノードはありません" linkTo={adminCategoryEditPath} />
          <NodeList title="登録されている銘柄の一覧" items={node.brands} empty="銘柄はありません" linkTo={adminBrandEditPath} />
        </main>
      )}
    </ApiView>
  )
}

/** 子のノード・直下の銘柄の一覧。1 行は旧(ListComponent.vue)と同じく「名前(英名)」の編集画面へのリンク */
function NodeList({
  title,
  items,
  empty,
  linkTo,
}: {
  title: string
  items: readonly NodeSummary[]
  empty: string
  linkTo: (id: number) => string
}) {
  return (
    <section className={styles.list} aria-label={title}>
      <h2 className={styles.listTitle}>{title}</h2>
      {items.length === 0 ? (
        <p className={styles.empty}>{empty}</p>
      ) : (
        <ul className={styles.items}>
          {items.map((item) => (
            <li key={item.id}>
              <Link to={linkTo(item.id)}>
                {item.name}({item.name_eng})
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
