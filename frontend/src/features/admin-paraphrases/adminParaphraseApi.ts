import { toApiResult, type ApiResult, type FetchResult } from '../../api/apiResult'
import { api } from '../../api/client'
import type { components } from '../../api/schema'
import type { AdminListPage, AdminListQuery } from '../../components/AdminList/AdminList'

type ParaphraseResource = components['schemas']['ParaphraseResource']
type SkipWordResource = components['schemas']['SkipWordResource']
export type SaveParaphraseBody = components['schemas']['SaveParaphraseRequest']
export type SaveSkipWordBody = components['schemas']['SaveSkipWordRequest']

/** 欄ごとの誤り(422 の errors。キーは API の項目の名前) */
export type FieldErrors = Record<string, string[]>

/** 保存・削除が、入力の誤りのほかの理由で失敗したとき(通信の失敗か、エラーの応答) */
export type RequestFailure = Exclude<ApiResult<never>, { kind: 'success' }>

export type SaveResult<T> =
  | { kind: 'saved'; data: T }
  /** 入力の誤り。画面は欄ごとに出し、入力を残して画面に残る(docs/decisions/admin-screens.md の既定) */
  | { kind: 'invalid'; errors: FieldErrors }
  | RequestFailure

export type DeleteResult = { kind: 'deleted' } | RequestFailure

/** 言い換え辞書の一覧の 1 ページ(AdminList の load。q は変換前・変換後・ミドルの部分一致) */
export const loadParaphrases = ({ q, page }: AdminListQuery) =>
  api.GET('/admin/paraphrases', { params: { query: { q, page } } })

/**
 * スキップ語の一覧(AdminList の load)。API はページに分けずに全部を返す(旧で 5 件)ので、1 ページだけの一覧の形にする。
 * 絞り込みの欄は出さないので、条件は使わない
 */
export async function loadSkipWords(): Promise<FetchResult<AdminListPage<SkipWordResource>>> {
  const { data, response } = await api.GET('/admin/skip-words')
  return {
    data: data === undefined ? undefined : { data: data.data, meta: { current_page: 1, last_page: 1 } },
    response,
  }
}

/** 木から選ぶ欄に出す、カテゴリの木(検索フォームと同じ API。根から一番深い段まで) */
export const fetchCategoryTree = () => api.GET('/search-form')

/** 木で選んだノードと、その配下すべての銘柄(公開のノードの詳細の API) */
export const fetchCategoryBrands = (id: number) =>
  api.GET('/categories/{category}', { params: { path: { category: id } } })

/** 言い換えを保存する。id が null なら足し(POST)、あれば書き換える(PUT) */
export const saveParaphrase = (id: number | null, body: SaveParaphraseBody): Promise<SaveResult<ParaphraseResource>> =>
  toSaveResult(() =>
    id === null
      ? api.POST('/admin/paraphrases', { body })
      : api.PUT('/admin/paraphrases/{paraphrase}', { params: { path: { paraphrase: id } }, body }),
  )

/** 言い換えを消す */
export const deleteParaphrase = (id: number): Promise<DeleteResult> =>
  toDeleteResult(() => api.DELETE('/admin/paraphrases/{paraphrase}', { params: { path: { paraphrase: id } } }))

/** スキップ語を保存する。id が null なら足し(POST)、あれば書き換える(PUT) */
export const saveSkipWord = (id: number | null, body: SaveSkipWordBody): Promise<SaveResult<SkipWordResource>> =>
  toSaveResult(() =>
    id === null
      ? api.POST('/admin/skip-words', { body })
      : api.PUT('/admin/skip-words/{skipWord}', { params: { path: { skipWord: id } }, body }),
  )

/** スキップ語を消す */
export const deleteSkipWord = (id: number): Promise<DeleteResult> =>
  toDeleteResult(() => api.DELETE('/admin/skip-words/{skipWord}', { params: { path: { skipWord: id } } }))

type SaveResponse<T> = { data?: { data: T }; error?: unknown; response: Response }

/** 保存の応答を、保存できた・入力の誤り(422)・そのほかの失敗に分ける。通信の失敗(例外)も種類で返す */
async function toSaveResult<T>(request: () => Promise<SaveResponse<T>>): Promise<SaveResult<T>> {
  let result: SaveResponse<T>
  try {
    result = await request()
  } catch {
    return { kind: 'network-error' }
  }
  const { data, error, response } = result
  if (data !== undefined) {
    return { kind: 'saved', data: data.data }
  }
  if (response.status === 422 && hasFieldErrors(error)) {
    return { kind: 'invalid', errors: error.errors }
  }
  return response.status === 404 ? { kind: 'not-found' } : { kind: 'http-error', status: response.status }
}

async function toDeleteResult(request: () => Promise<FetchResult<unknown>>): Promise<DeleteResult> {
  const result = await toApiResult(request)
  return result.kind === 'success' ? { kind: 'deleted' } : result
}

const hasFieldErrors = (error: unknown): error is { errors: FieldErrors } =>
  typeof error === 'object' && error !== null && 'errors' in error && typeof error.errors === 'object'

/** 保存・削除が失敗したときに出す文。入力は残すので、そう書く */
export function failureMessage(failure: RequestFailure, action: '保存' | '削除'): string {
  switch (failure.kind) {
    case 'network-error':
      return `通信に失敗して${action}できませんでした。接続を確かめて、もう一度お試しください。`
    case 'not-found':
      return `${action}する語が見つかりませんでした。ほかの画面で消された可能性があります。一覧を読み直してください。`
    case 'http-error':
      return `${action}できませんでした(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
  }
}
