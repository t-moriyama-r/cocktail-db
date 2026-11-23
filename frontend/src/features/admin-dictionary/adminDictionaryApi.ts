import { api } from '../../api/client'
import type { components } from '../../api/schema'
import { toDictionaryFormData, toSaveDictionaryBody, type DictionaryFormValues } from './dictionaryFormValues'

type DictionaryResource = components['schemas']['DictionaryResource']

/** 欄ごとの誤り(422 の errors。キーは API の項目の名前) */
export type FieldErrors = Record<string, string[]>

/** 保存・削除が、検証のほかの理由で失敗したとき(通信の失敗か、エラーの応答) */
export type RequestFailure = { kind: 'network-error' } | { kind: 'http-error'; status: number }

export type SaveResult =
  | { kind: 'saved'; data: DictionaryResource }
  /** 入力の誤り。画面は欄ごとに出し、画面を移らない(#284 の既定) */
  | { kind: 'invalid'; errors: FieldErrors }
  /**
   * 422 で、冪等キーを断られた(`errors.idempotency_key`)。同じキーの前の送信が保存済みで、今の送信と中身が違う
   * (応答が返らなかった後に入力を直して送った)とき。docs/decisions/admin-idempotency.md の決まったこと 5
   */
  | { kind: 'key-rejected' }
  | RequestFailure

export type DeleteResult =
  | { kind: 'deleted' }
  /** 消せない(カクテルのグラスに使われている・技法を説明する用語になっている。#315)。理由の文をそのまま出す */
  | { kind: 'blocked'; reasons: string[] }
  | RequestFailure

/** 分類が選ばれていないときの誤り。送る前に画面で出す(分類のほかの検証は API に任せる) */
const CATEGORY_REQUIRED: FieldErrors = { dictionary_category_id: ['分類を選んでください。'] }

/** 一覧(分類ごとの用語)。新規・編集の画面では、分類の選択肢にも使う */
export const fetchAdminDictionaries = () => api.GET('/admin/dictionaries')

/** 編集で開く用語 */
export const fetchAdminDictionary = (id: number) =>
  api.GET('/admin/dictionaries/{dictionary}', { params: { path: { dictionary: id } } })

/**
 * 用語を保存する。id が null なら新規(POST)、あれば更新(PUT。multipart なので client が POST と `_method=PUT` にする)。
 * 新規は、idempotencyKey を `Idempotency-Key` ヘッダで送る(docs/decisions/admin-idempotency.md。#791。省くと付けない)。
 * 同じキーの再送には BE が 1 回目の応答を返す(`Idempotent-Replayed: true`)ので、ふつうの成功と同じく saved にする。
 * 更新は、同じ内容を送り直しても行が増えないので、キーを付けない
 */
export async function saveDictionary(
  id: number | null,
  values: DictionaryFormValues,
  idempotencyKey?: string,
): Promise<SaveResult> {
  const body = toSaveDictionaryBody(values)
  if (body === null) {
    return { kind: 'invalid', errors: CATEGORY_REQUIRED }
  }
  const bodySerializer = () => toDictionaryFormData(body, values.image)
  try {
    const { data, error, response } =
      id === null
        ? await api.POST('/admin/dictionaries', {
            params: { header: idempotencyKey === undefined ? undefined : { 'Idempotency-Key': idempotencyKey } },
            body,
            bodySerializer,
          })
        : await api.PUT('/admin/dictionaries/{dictionary}', { params: { path: { dictionary: id } }, body, bodySerializer })
    if (data !== undefined) {
      return { kind: 'saved', data: data.data }
    }
    if (response.status === 422 && error !== undefined && 'errors' in error) {
      if (error.errors.idempotency_key !== undefined) return { kind: 'key-rejected' }
      return { kind: 'invalid', errors: error.errors }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}

/** 用語を消す */
export async function deleteDictionary(id: number): Promise<DeleteResult> {
  try {
    const { error, response } = await api.DELETE('/admin/dictionaries/{dictionary}', {
      params: { path: { dictionary: id } },
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
