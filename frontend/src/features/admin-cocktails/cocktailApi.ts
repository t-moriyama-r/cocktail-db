import type { Middleware } from 'openapi-fetch'
import { api } from '../../api/client'
import type { components, paths } from '../../api/schema'
import { toCocktailFormData, type CocktailFormValues } from './cocktailFormValues'

type SaveCocktailRequest = components['schemas']['SaveCocktailRequest']

/** 項目ごとの検証のメッセージ(Laravel の 422 の errors。キーは API の項目名。材料の行は `ingredients.0.amount` のような形) */
export type CocktailFieldErrors = Readonly<Record<string, readonly string[]>>

/** 応答がエラーだったとき(422・409 のように画面が中身を読むもの以外) */
type Failure =
  /** 中身を読まないエラーの応答 */
  | { kind: 'http-error'; status: number }
  /** 応答が返らなかった */
  | { kind: 'network-error' }

/** 保存の応答の warnings の 1 件(別名がほかのカクテルの名前・別名と同じ。#293) */
type SaveWarning = paths['/admin/cocktails']['post']['responses'][201]['content']['application/json']['warnings'][number]

/**
 * 保存の後の警告を、画面に出す形にしたもの(#83)。保存は済んでいる(警告は保存を止めない)。
 * alias は警告の別名の名前(応答の field の `aliases.<番号>` を、保存した別名の並びで引いたもの)
 */
export type CocktailAliasWarning = { alias: string; message: string; cocktailId: number; cocktailName: string }

/** 保存の結果。画面は、成功なら一覧へ戻り、それ以外は画面に残ってメッセージを出す */
export type SaveCocktailResult =
  /** name は保存したカクテルの名前。warnings は別名の警告(無ければ空) */
  | { kind: 'saved'; name: string; warnings: readonly CocktailAliasWarning[] }
  /** 422。項目ごとのメッセージ */
  | { kind: 'invalid'; errors: CocktailFieldErrors }
  | Failure

/** 削除を断られた理由(409。ほかの説明文から参照されている。#579) */
export type DeleteConflictReason =
  paths['/admin/cocktails/{cocktail}']['delete']['responses'][409]['content']['application/json']['reasons'][number]

/** 削除の結果。画面は、消せたらメニューへ移り、それ以外は画面に残ってメッセージを出す */
export type DeleteCocktailResult =
  | { kind: 'deleted' }
  /** 409。消せない理由と、参照している行 */
  | { kind: 'conflict'; reasons: readonly DeleteConflictReason[] }
  | Failure

/**
 * multipart の本文を、型付きの client に渡す。openapi-fetch は本文が FormData ならそのまま送るが、型は API の項目の形
 * (SaveCocktailRequest)を求めるので、ここで当てる。項目の名前と値の形は toCocktailFormData が API に合わせて作る
 */
const multipartBody = (form: FormData) => form as unknown as SaveCocktailRequest

/**
 * 更新を POST で送る。PHP は PUT の multipart を読まないので、API は POST に `_method=PUT` を付けた更新を受ける
 * (Laravel のメソッドの上書き。SaveCocktailRequest の説明)。型付きの client は API の定義どおり PUT で組み立てるので、
 * 送る直前にメソッドだけを POST にする(銘柄の保存と同じ)
 */
const sendAsPost: Middleware = {
  onRequest({ request }) {
    return new Request(request, { method: 'POST' })
  },
}

/**
 * カクテルを保存する。cocktailId が null なら新規(POST /api/admin/cocktails)、あれば更新(PUT /api/admin/cocktails/{id})。
 * isDraft は押したボタン(「下書きとして保存」なら true、「公開する」なら false)
 */
export async function saveCocktail(
  cocktailId: number | null,
  values: CocktailFormValues,
  isDraft: boolean,
): Promise<SaveCocktailResult> {
  try {
    const { data, response, error } =
      cocktailId === null
        ? await api.POST('/admin/cocktails', { body: multipartBody(toCocktailFormData(values, 'POST', isDraft)) })
        : await api.PUT('/admin/cocktails/{cocktail}', {
            params: { path: { cocktail: cocktailId } },
            body: multipartBody(toCocktailFormData(values, 'PUT', isDraft)),
            middleware: [sendAsPost],
          })
    if (response.ok) {
      return {
        kind: 'saved',
        name: data?.data.name ?? values.name,
        warnings: (data?.warnings ?? []).map((warning) => aliasWarningOf(warning, data?.data.aliases ?? [])),
      }
    }
    if (response.status === 422 && error !== undefined && 'errors' in error) {
      return { kind: 'invalid', errors: error.errors }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}

/** 応答の警告の field(`aliases.<番号>`)を、保存した別名の名前にする。読めなければ field をそのまま出す */
function aliasWarningOf(warning: SaveWarning, aliases: readonly string[]): CocktailAliasWarning {
  const index = /^aliases\.(\d+)$/.exec(warning.field)?.[1]
  const alias = index === undefined ? undefined : aliases[Number(index)]
  return { alias: alias ?? warning.field, message: warning.message, cocktailId: warning.cocktail_id, cocktailName: warning.cocktail_name }
}

/** カクテルを消す(DELETE /api/admin/cocktails/{id}。#130) */
export async function deleteCocktail(cocktailId: number): Promise<DeleteCocktailResult> {
  try {
    const { response, error } = await api.DELETE('/admin/cocktails/{cocktail}', { params: { path: { cocktail: cocktailId } } })
    if (response.ok) {
      return { kind: 'deleted' }
    }
    if (response.status === 409 && error !== undefined && 'reasons' in error) {
      return { kind: 'conflict', reasons: error.reasons }
    }
    return { kind: 'http-error', status: response.status }
  } catch {
    return { kind: 'network-error' }
  }
}
