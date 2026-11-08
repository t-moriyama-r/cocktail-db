import type { Middleware } from 'openapi-fetch'
import { api } from '../../api/client'
import type { components, paths } from '../../api/schema'
import { toCategoryFormData, type CategoryFormValues, type CategoryKeptValues } from './categoryFormValues'

type SaveCategoryRequest = components['schemas']['SaveCategoryRequest']

/** 管理 API の木(GET /api/admin/categories。#123)。カテゴリ木の画面・新規の親・付け替えの移す先で使う */
export const fetchAdminCategoryTree = () => api.GET('/admin/categories')

/** 項目ごとの検証のメッセージ(Laravel の 422 の errors。キーは API の項目名) */
export type CategoryFieldErrors = Readonly<Record<string, readonly string[]>>

/** 保存の後の警告(保存はできている)。今は、英名が同じ親の下のほかのノードと同じときだけ返る */
export type CategorySaveWarning = { field: string; message: string }

/** 保存の結果。画面は、成功ならカテゴリ木へ戻り、それ以外は画面に残ってメッセージを出す */
export type SaveCategoryResult =
  | { kind: 'saved'; warnings: readonly CategorySaveWarning[] }
  /** 422。項目ごとのメッセージ */
  | { kind: 'invalid'; errors: CategoryFieldErrors }
  /** 422 以外のエラーの応答 */
  | { kind: 'http-error'; status: number }
  /** 応答が返らなかった */
  | { kind: 'network-error' }

/**
 * multipart の本文を、型付きの client に渡す。openapi-fetch は本文が FormData ならそのまま送るが、型は API の項目の形
 * (SaveCategoryRequest)を求めるので、ここで当てる。項目の名前と値の形は toCategoryFormData が API に合わせて作る
 */
const multipartBody = (form: FormData) => form as unknown as SaveCategoryRequest

/**
 * 更新を POST で送る。PHP は PUT の multipart を読まないので、API は POST に `_method=PUT` を付けた更新を受ける
 * (Laravel のメソッドの上書き。SaveCategoryRequest の説明)。型付きの client は API の定義どおり PUT で組み立てるので、
 * 送る直前にメソッドだけを POST にする
 */
const sendAsPost: Middleware = {
  onRequest({ request }) {
    return new Request(request, { method: 'POST' })
  },
}

/** 重なりを問い合わせる欄(API の項目名。重なりの問い合わせの応答の warnings の field) */
export type DuplicateField =
  paths['/admin/categories/duplicates']['get']['responses'][200]['content']['application/json']['warnings'][number]['field']

/**
 * 名前か英名が、同じ親の下のほかのノードと重なるかを問い合わせる(GET /api/admin/categories/duplicates。#673)。
 * parentId が null なら根どうしで比べ、nodeId(編集しているノード)は数えない。
 * 重なれば API の文(「同じ名前が存在しています」)を、重ならなければ null を返す。
 * 問い合わせに失敗したときも null を返し、重ならないときと区別しない(旧も失敗したときは何も出さなかった)
 */
export async function findCategoryDuplicate(
  field: DuplicateField,
  value: string,
  parentId: number | null,
  nodeId: number | null,
): Promise<string | null> {
  // openapi-fetch は null の項目をクエリに入れないので、根どうし(親が null)・新規(nodeId が null)は項目ごと送らない
  const scope = { parent_id: parentId, exclude_id: nodeId }
  try {
    const { data } = await api.GET('/admin/categories/duplicates', {
      params: { query: field === 'name' ? { ...scope, name: value } : { ...scope, name_eng: value } },
    })
    return data?.warnings.find((warning) => warning.field === field)?.message ?? null
  } catch {
    return null
  }
}

/** ノードを保存する。nodeId が null なら新規(POST /api/admin/categories)、あれば更新(PUT /api/admin/categories/{id}) */
export async function saveCategory(
  nodeId: number | null,
  values: CategoryFormValues,
  kept: CategoryKeptValues,
): Promise<SaveCategoryResult> {
  try {
    const { data, response, error } =
      nodeId === null
        ? await api.POST('/admin/categories', { body: multipartBody(toCategoryFormData(values, kept, 'POST')) })
        : await api.PUT('/admin/categories/{category}', {
            params: { path: { category: nodeId } },
            body: multipartBody(toCategoryFormData(values, kept, 'PUT')),
            middleware: [sendAsPost],
          })
    if (data !== undefined) {
      const warnings: CategorySaveWarning[] = []
      for (const warning of data.warnings) {
        if (typeof warning === 'object') warnings.push({ field: warning.field, message: warning.message })
      }
      return { kind: 'saved', warnings }
    }
    if (response.status === 422 && error !== undefined && 'errors' in error) {
      return { kind: 'invalid', errors: error.errors }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}

/** 削除の結果 */
export type DeleteCategoryResult =
  | { kind: 'deleted' }
  /** 409。消せない理由(子・銘柄・材料・ベース・アフィリエイト。#121)の文 */
  | { kind: 'blocked'; reasons: readonly string[] }
  /** 409 以外のエラーの応答(無いノードの 404 など) */
  | { kind: 'http-error'; status: number }
  /** 応答が返らなかった */
  | { kind: 'network-error' }

/** ノードを消す(DELETE /api/admin/categories/{id})。消せないときは、API が返す理由をすべて返す */
export async function deleteCategory(nodeId: number): Promise<DeleteCategoryResult> {
  try {
    const { error, response } = await api.DELETE('/admin/categories/{category}', {
      params: { path: { category: nodeId } },
    })
    if (response.ok) {
      return { kind: 'deleted' }
    }
    if (response.status === 409 && error !== undefined && 'reasons' in error) {
      return { kind: 'blocked', reasons: error.reasons.map((reason) => reason.message) }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}
