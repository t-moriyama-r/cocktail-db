/**
 * API を呼んだ結果を、画面が出し分ける種類に分けたもの。
 * 画面はこの種類ごとに表示を決める(読み込み中を足した `ApiState` は useApi.ts、表示の部品は components/ApiView)。
 */
export type ApiResult<T> =
  | { kind: 'success'; data: T }
  /** 404。存在しない ID の詳細など。画面は 404 の画面を出す */
  | { kind: 'not-found' }
  /** 404 以外のエラーの応答(4xx・5xx) */
  | { kind: 'http-error'; status: number }
  /** 応答が返らなかった(通信の失敗) */
  | { kind: 'network-error' }

/** openapi-fetch の `api.GET(...)` などが返すもののうち、ここで使う部分 */
export type FetchResult<T> = { data?: T; response: Response }

/**
 * openapi-fetch で API を呼び、結果を ApiResult に分ける。
 * openapi-fetch は、エラーの応答では例外にせず `error` を返し、通信の失敗では例外にする。
 * どちらも画面で同じように扱えるよう、例外を投げずに種類で返す。
 */
export async function toApiResult<T>(request: () => Promise<FetchResult<T>>): Promise<ApiResult<T>> {
  let result: FetchResult<T>
  try {
    result = await request()
  } catch {
    return { kind: 'network-error' }
  }

  const { data, response } = result
  if (response.ok) {
    // 204 など本文のない成功応答は data が undefined になる。それも成功として扱う(T は void などにする)
    return { kind: 'success', data: data as T }
  }
  if (response.status === 404) {
    return { kind: 'not-found' }
  }
  return { kind: 'http-error', status: response.status }
}
