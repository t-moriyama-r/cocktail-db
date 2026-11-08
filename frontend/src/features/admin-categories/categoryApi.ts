import type { Middleware } from 'openapi-fetch'
import { api } from '../../api/client'
import type { components } from '../../api/schema'
import { toCategoryFormData, type CategoryFormValues, type CategoryKeptValues } from './categoryFormValues'
import { appendFamousBrand, type FamousBrandValues } from './famousBrandValues'

type SaveCategoryRequest = components['schemas']['SaveCategoryRequest']

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

/**
 * ノードを保存する。nodeId が null なら新規(POST /api/admin/categories)、あれば更新(PUT /api/admin/categories/{id})。
 * famousBrand は有名な銘柄の欄の入力で、根(欄を出さない)なら null にして送らない(API は根に有名な銘柄を入れさせない)
 */
export async function saveCategory(
  nodeId: number | null,
  values: CategoryFormValues,
  kept: CategoryKeptValues,
  famousBrand: FamousBrandValues | null,
): Promise<SaveCategoryResult> {
  const body = (method: 'POST' | 'PUT') => {
    const form = toCategoryFormData(values, kept, method)
    if (famousBrand !== null) {
      appendFamousBrand(form, famousBrand)
    }
    return multipartBody(form)
  }
  try {
    const { data, response, error } =
      nodeId === null
        ? await api.POST('/admin/categories', { body: body('POST') })
        : await api.PUT('/admin/categories/{category}', {
            params: { path: { category: nodeId } },
            body: body('PUT'),
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
