import type { paths } from '../api/schema'

/**
 * テストで API を差し替える仕組み。setup.ts が `api/client` を、ここの `fetchApi` で応答する同じ型付きのクライアントに置き換える。
 * テストからは `mockApi`(スキーマの型を当てた JSON の応答)か `mockApiHandler`(スキーマに無い応答・通信の失敗)で
 * 「このメソッドとパスにこの応答を返す」を登録する。登録はテストごとに setup.ts が消す。
 */

/** 本物の client は相対 URL(/api)で作られていて Node の fetch では解決できないため、テストでは絶対 URL にする */
export const TEST_API_BASE_URL = 'http://localhost/api'

type HttpMethod = 'get' | 'put' | 'post' | 'delete' | 'patch'

/** そのメソッドを持つパス(schema.ts で `put?: never` のように無いメソッドは外れる) */
type PathWith<M extends HttpMethod> = {
  [P in keyof paths]: paths[P] extends Record<M, { responses: object }> ? P : never
}[keyof paths]

type Responses<M extends HttpMethod, P extends PathWith<M>> = paths[P] extends Record<M, { responses: infer R }> ? R : never

/** スキーマに JSON の本文が定義されているステータス */
type JsonStatus<M extends HttpMethod, P extends PathWith<M>> = {
  [S in keyof Responses<M, P>]: Responses<M, P>[S] extends { content: { 'application/json': unknown } } ? S : never
}[keyof Responses<M, P>] &
  number

type JsonBody<M extends HttpMethod, P extends PathWith<M>, S extends JsonStatus<M, P>> =
  Responses<M, P>[S] extends { content: { 'application/json': infer B } } ? B : never

type Handler = (request: Request) => Response | Promise<Response>

type Route = { method: string; pattern: RegExp; handler: Handler }

const routes: Route[] = []
const requests: Request[] = []

/** `/cocktails/{cocktail}` のようなスキーマのパスを、実際の URL のパス名に当てる正規表現にする */
const toPattern = (path: string) => {
  const basePath = new URL(TEST_API_BASE_URL).pathname
  const escaped = path
    .split(/\{[^}]+\}/)
    .map((part) => part.replace(/[.*+?^$()|[\]\\]/g, '\\$&'))
    .join('[^/]+')
  return new RegExp(`^${basePath}${escaped}$`)
}

/** JSON の応答を作る。スキーマに無い応答(エラーの本文など)を mockApiHandler で返すときにも使う */
export const jsonResponse = (body: unknown, status: number) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

/** スキーマのパスとメソッドに、そのまま返す応答を登録する。スキーマに無い応答・通信の失敗・返らない応答を作るときに使う */
export const mockApiHandler = <M extends HttpMethod>(method: M, path: PathWith<M>, handler: Handler) => {
  routes.push({ method: method.toUpperCase(), pattern: toPattern(path), handler })
}

/** スキーマのパスとメソッドに、JSON の応答を登録する。本文には schema.ts の型を当てるので、BE とずれると型エラーになる */
export const mockApi = <M extends HttpMethod, P extends PathWith<M>, S extends JsonStatus<M, P>>(
  method: M,
  path: P,
  status: S,
  body: JsonBody<M, P, S>,
) => {
  mockApiHandler(method, path, () => jsonResponse(body, status))
}

/** 画面が送ったリクエストを、送った順に返す(メソッドとパスを確かめるため) */
export const apiRequests = (): readonly Request[] => requests

/** 差し替えた client の fetch。登録の無いリクエストは、どれか分かるように例外にする */
export const fetchApi = async (request: Request): Promise<Response> => {
  requests.push(request)
  const { pathname } = new URL(request.url)
  const route = routes.findLast((candidate) => candidate.method === request.method && candidate.pattern.test(pathname))
  if (!route) {
    throw new Error(`テストで応答を登録していない API: ${request.method} ${pathname}`)
  }
  return route.handler(request)
}

/** 登録した応答と、送られたリクエストの記録を消す(setup.ts がテストごとに呼ぶ) */
export const resetApiMocks = () => {
  routes.length = 0
  requests.length = 0
}
