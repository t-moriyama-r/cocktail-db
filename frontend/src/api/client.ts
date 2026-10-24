import createClient from 'openapi-fetch'
import type { paths } from './schema'

/**
 * Laravel の OpenAPI(openapi/openapi.json)から生成した型付きの API クライアント。
 * パス・パラメータ・レスポンスの型は schema.ts から来るので、BE の変更は型エラーとして現れる。
 */
export const api = createClient<paths>({ baseUrl: '/api' })
