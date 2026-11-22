import type { Middleware } from 'openapi-fetch'
import { api } from '../../api/client'
import type { components } from '../../api/schema'
import { toBrandFormData, type BrandFormValues } from './brandFormValues'

type SaveBrandRequest = components['schemas']['SaveBrandRequest']

/** 項目ごとの検証のメッセージ(Laravel の 422 の errors。キーは API の項目名) */
export type BrandFieldErrors = Readonly<Record<string, readonly string[]>>

/** 保存の結果。画面は、成功なら一覧へ戻り、それ以外は画面に残ってメッセージを出す */
export type SaveBrandResult =
  | { kind: 'saved' }
  /** 422。項目ごとのメッセージ */
  | { kind: 'invalid'; errors: BrandFieldErrors }
  /** 422 以外のエラーの応答 */
  | { kind: 'http-error'; status: number }
  /** 応答が返らなかった */
  | { kind: 'network-error' }

/**
 * multipart の本文を、型付きの client に渡す。openapi-fetch は本文が FormData ならそのまま送るが、型は API の項目の形
 * (SaveBrandRequest)を求めるので、ここで当てる。項目の名前と値の形は toBrandFormData が API に合わせて作る
 */
const multipartBody = (form: FormData) => form as unknown as SaveBrandRequest

/**
 * 更新を POST で送る。PHP は PUT の multipart を読まないので、API は POST に `_method=PUT` を付けた更新を受ける
 * (Laravel のメソッドの上書き。SaveBrandRequest の説明)。型付きの client は API の定義どおり PUT で組み立てるので、
 * 送る直前にメソッドだけを POST にする
 */
const sendAsPost: Middleware = {
  onRequest({ request }) {
    return new Request(request, { method: 'POST' })
  },
}

/** 重なりを問い合わせる欄(API の項目名) */
export type DuplicateField = 'name' | 'name_eng'

/**
 * 名前か英名が、ほかの銘柄と重なるかを問い合わせる(GET /api/admin/brands/duplicates。#632)。
 * 重なれば API の文(「同じ名前が存在しています」)を、重ならなければ null を返す。brandId(編集している銘柄)は数えない。
 * 問い合わせに失敗したときも null を返す(重なりは知らせるだけで保存を止めないので、失敗を画面に出さない。
 * 旧も失敗したときは何も出さなかった)
 */
export async function findBrandDuplicate(field: DuplicateField, value: string, brandId: number | null): Promise<string | null> {
  const excludeId = brandId ?? undefined
  try {
    const { data } = await api.GET('/admin/brands/duplicates', {
      params: { query: field === 'name' ? { name: value, exclude_id: excludeId } : { name_eng: value, exclude_id: excludeId } },
    })
    return data?.warnings.find((warning) => warning.field === field)?.message ?? null
  } catch {
    return null
  }
}

/** 銘柄を保存する。brandId が null なら新規(POST /api/admin/brands)、あれば更新(PUT /api/admin/brands/{id}) */
export async function saveBrand(brandId: number | null, values: BrandFormValues): Promise<SaveBrandResult> {
  try {
    const { response, error } =
      brandId === null
        ? await api.POST('/admin/brands', { body: multipartBody(toBrandFormData(values, 'POST')) })
        : await api.PUT('/admin/brands/{brand}', {
            params: { path: { brand: brandId } },
            body: multipartBody(toBrandFormData(values, 'PUT')),
            middleware: [sendAsPost],
          })
    if (response.ok) {
      return { kind: 'saved' }
    }
    if (response.status === 422 && error !== undefined && 'errors' in error) {
      return { kind: 'invalid', errors: error.errors }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}
