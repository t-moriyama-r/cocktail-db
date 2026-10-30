import { describe, expect, test } from 'vitest'
import {
  defaultSearchFormValues,
  ingredientError,
  toggleMethod,
  toSearchConditions,
  type SearchFormValues,
} from './searchForm'

/** GET /api/search-form の methods(旧の 6 つの技法) */
const methods = [1, 2, 3, 4, 5, 6].map((id) => ({ id }))

const withIngredients = (...ids: number[]): SearchFormValues => ({ ...defaultSearchFormValues, selection: new Set(ids) })

describe('searchForm', () => {
  test('TC-307-01: 既定値は OR 検索・かんたん検索なし・技法はすべて・材料なし', () => {
    expect(toSearchConditions(methods, defaultSearchFormValues)).toEqual({
      mode: 'or',
      isEasy: false,
      methodIds: [1, 2, 3, 4, 5, 6],
      selection: new Set(),
    })
  })

  test('TC-307-02: 材料が 0 個なら「材料は必須です。」', () => {
    expect(ingredientError(defaultSearchFormValues)).toBe('材料は必須です。')
  })

  test('TC-307-03: 材料が 1 個なら誤りは無い', () => {
    expect(ingredientError(withIngredients(100))).toBeNull()
  })

  test('TC-307-04: 技法を 0 個にしても誤りは無く、技法は空で渡す(すべての技法として送る)', () => {
    const values = methods.reduce((current, method) => toggleMethod(current, method.id, false), withIngredients(100))

    expect(ingredientError(values)).toBeNull()
    expect(toSearchConditions(methods, values).methodIds).toEqual([])
  })

  test('TC-307-05: 技法を 1 個だけ残すと、その 1 個を渡す。外した技法を入れ直すと元に戻り、重ならない', () => {
    const one = [2, 3, 4, 5, 6].reduce((current, id) => toggleMethod(current, id, false), withIngredients(100))
    expect(toSearchConditions(methods, one).methodIds).toEqual([1])

    const back = toggleMethod(toggleMethod(one, 3, true), 3, true)
    expect(toSearchConditions(methods, back).methodIds).toEqual([1, 3])
  })
})
