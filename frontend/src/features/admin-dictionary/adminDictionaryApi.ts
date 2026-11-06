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
 */
export async function saveDictionary(id: number | null, values: DictionaryFormValues): Promise<SaveResult> {
  const body = toSaveDictionaryBody(values)
  if (body === null) {
    return { kind: 'invalid', errors: CATEGORY_REQUIRED }
  }
  const bodySerializer = () => toDictionaryFormData(body, values.image)
  try {
    const { data, error, response } =
      id === null
        ? await api.POST('/admin/dictionaries', { body, bodySerializer })
        : await api.PUT('/admin/dictionaries/{dictionary}', { params: { path: { dictionary: id } }, body, bodySerializer })
    if (data !== undefined) {
      return { kind: 'saved', data: data.data }
    }
    if (response.status === 422 && error !== undefined && 'errors' in error) {
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
