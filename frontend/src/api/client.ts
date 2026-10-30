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

/**
 * 型付きの API クライアントを作る。テスト(test/setup.ts)は、URL と fetch だけを差し替えてこれで作る。
 * 書き込みに CSRF のトークンを付ける処理(xsrfMiddleware)も、テストの client に同じく付く。
 */
export const createApiClient = (options: ClientOptions) => {
  const client = createClient<paths>(options)
  client.use(xsrfMiddleware)
  return client
}

/**
 * Laravel の OpenAPI(openapi/openapi.json)から生成した型付きの API クライアント。
 * パス・パラメータ・レスポンスの型は schema.ts から来るので、BE の変更は型エラーとして現れる。
 */
export const api = createApiClient({ baseUrl: '/api' })
