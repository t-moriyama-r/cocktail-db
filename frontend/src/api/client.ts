import createClient, { type ClientOptions, type Middleware } from 'openapi-fetch'
import type { paths } from './schema'

/** 書き込みでないメソッド。Laravel の CSRF の確かめも、これらは見ない */
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/** Laravel が画面を返すときに付ける、CSRF のトークンのクッキー */
const XSRF_COOKIE = 'XSRF-TOKEN'

/** クッキーの値を返す。無ければ null */
const readCookie = (name: string): string | null => {
  const prefix = `${name}=`
  const found = document.cookie.split('; ').find((cookie) => cookie.startsWith(prefix))
  return found === undefined ? null : decodeURIComponent(found.slice(prefix.length))
}

/**
 * 書き込み(POST・PUT・DELETE など)に、XSRF-TOKEN のクッキーの値を X-XSRF-TOKEN のヘッダーで付ける。
 * 管理 API(/api/admin/*)は、ログインのセッションで呼ぶ書き込みの CSRF を確かめる(#118)。
 * Laravel は画面(SPA)を返すときに XSRF-TOKEN のクッキーを付け、ヘッダーの値と突き合わせる。
 * fetch はこのヘッダーを自分では付けないので、ここで付ける。
 */
const xsrfMiddleware: Middleware = {
  onRequest({ request }) {
    if (SAFE_METHODS.has(request.method)) {
      return undefined
    }
    const token = readCookie(XSRF_COOKIE)
    if (token !== null) {
      request.headers.set('X-XSRF-TOKEN', token)
    }
    return request
  },
}

/** POST に置き換えて、元のメソッドを `_method` で伝える(Laravel のメソッドの偽装)メソッド */
const OVERRIDDEN_METHODS = new Set(['PUT', 'PATCH'])

/**
 * multipart(画像つき)の PUT・PATCH を、POST に `_method` を付けたリクエストに置き換える。
 * PHP は PUT の multipart の本文を読まないので、管理 API の更新は POST に `_method=PUT` を付けて送る決まり
 * (SaveDictionaryRequest など)。Laravel はこれを PUT のルートとして受ける。
 * 画面は、スキーマのとおり `api.PUT` で呼べばよい(本文を FormData にしたときだけ置き換わる)。
 */
const multipartMethodOverrideMiddleware: Middleware = {
  async onRequest({ request }) {
    const contentType = request.headers.get('Content-Type') ?? ''
    if (!OVERRIDDEN_METHODS.has(request.method) || !contentType.startsWith('multipart/form-data')) {
      return undefined
    }
    const form = await request.formData()
    form.set('_method', request.method)
    const headers = new Headers(request.headers)
    // 本文を作り直すと multipart の区切りが変わるので、Content-Type は fetch に付け直させる
    headers.delete('Content-Type')
    return new Request(request.url, { method: 'POST', headers, body: form, credentials: request.credentials, signal: request.signal })
  },
}

/**
 * 型付きの API クライアントを作る。テスト(test/setup.ts)は、URL と fetch だけを差し替えてこれで作る。
 * 書き込みに CSRF のトークンを付ける処理(xsrfMiddleware)と、multipart の PUT を POST に置き換える処理も、
 * テストの client に同じく付く。
 */
export const createApiClient = (options: ClientOptions) => {
  const client = createClient<paths>(options)
  client.use(multipartMethodOverrideMiddleware, xsrfMiddleware)
  return client
}

/**
 * Laravel の OpenAPI(openapi/openapi.json)から生成した型付きの API クライアント。
 * パス・パラメータ・レスポンスの型は schema.ts から来るので、BE の変更は型エラーとして現れる。
 */
export const api = createApiClient({ baseUrl: '/api' })
