import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'

type DuplicatesBody = paths['/admin/cocktails/duplicates']['get']['responses'][200]['content']['application/json']

/** 重複チェックの結果。名前・英名がどちらも空のあいだは none(問い合わせない) */
export type CocktailDuplicatesState =
  | { kind: 'none' }
  | { kind: 'loaded'; sameName: DuplicatesBody['same_name']; sameRecipe: DuplicatesBody['same_recipe'] }
  | { kind: 'failed' }

/** 名前・英名を入れてから問い合わせるまでの間(入れるたびに問い合わせない) */
export const DUPLICATES_DELAY_MS = 300

const NONE = { kind: 'none' } as const

/**
 * 名前・英名が同じカクテル・同じレシピのカクテルを問い合わせる(旧の「重複チェック」。GET /api/admin/cocktails/duplicates。#129)。
 *
 * 旧(CocktailEdit.vue の getDubs)と同じく、名前・英名を変えるたびに問い合わせ直し、編集では開いたときにも問い合わせる。
 * 入れている途中は DUPLICATES_DELAY_MS だけ待ってから問い合わせ、次の結果が来るまでは前の結果を出したままにする。
 * 前の問い合わせの応答が後から返っても使わない。excludeId は編集しているカクテル(自分を一覧に出さない)。新規なら null。
 */
export function useCocktailDuplicates(name: string, nameEng: string, excludeId: number | null): CocktailDuplicatesState {
  const [result, setResult] = useState<CocktailDuplicatesState>(NONE)
  const empty = name === '' && nameEng === ''

  useEffect(() => {
    if (empty) {
      return
    }
    let current = true
    const timer = setTimeout(() => {
      api
        .GET('/admin/cocktails/duplicates', {
          params: {
            query: {
              // 空の欄は送らない(API は空なら比べない)
              name: name === '' ? undefined : name,
              name_eng: nameEng === '' ? undefined : nameEng,
              exclude_id: excludeId ?? undefined,
            },
          },
        })
        .then(({ data }) => {
          if (current) {
            setResult(data === undefined ? { kind: 'failed' } : { kind: 'loaded', sameName: data.same_name, sameRecipe: data.same_recipe })
          }
        })
        .catch(() => {
          if (current) setResult({ kind: 'failed' })
        })
    }, DUPLICATES_DELAY_MS)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [empty, name, nameEng, excludeId])

  return empty ? NONE : result
}
