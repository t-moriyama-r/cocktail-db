import { describe, expect, test } from 'vitest'
import { brandPlacementLabel, sanitizeDigits, sanitizeStrength, toHalfWidthAlphanumeric } from './brandFormValues'

const node = (id: number, name: string, isFamousBrand = false) => ({ id, name, name_eng: name, is_famous_brand: isFamousBrand })

describe('一覧の所属(brandPlacementLabel)', () => {
  test('TC-64-01: いちばん上の段と所属のノードだけを「/」でつなぎ、間の段は省く', () => {
    expect(brandPlacementLabel([node(2, 'ジン'), node(3, 'ドライジン'), node(4, 'ロンドンドライジン')])).toBe('ジン/ロンドンドライジン')
  })

  test('TC-64-02: 有名な銘柄になっている段は、その段の名前の前に ★ を付ける', () => {
    expect(brandPlacementLabel([node(2, 'ジン', true), node(3, 'ドライジン')])).toBe('★ジン/ドライジン')
    expect(brandPlacementLabel([node(2, 'ジン'), node(3, 'ドライジン', true)])).toBe('ジン/★ドライジン')
  })

  test('TC-64-03: 所属がいちばん上の段ならその 1 段だけ、パンくずが空なら空の文字', () => {
    expect(brandPlacementLabel([node(2, 'ジン', true)])).toBe('★ジン')
    expect(brandPlacementLabel([])).toBe('')
  })
})

describe('欄に入れた文字の整え方', () => {
  test('TC-64-04: 度数は数字と「.」だけを残し(全角の数字は半角に)、4 文字までにする', () => {
    expect(sanitizeStrength('4a0.５%')).toBe('40.5')
    expect(sanitizeStrength('12.345')).toBe('12.3')
    expect(sanitizeStrength('abc')).toBe('')
  })

  test('TC-64-05: 開始位置は数字だけを残し(全角の数字は半角に、「.」も捨てる)、指定の桁までの数にする', () => {
    expect(sanitizeDigits('１２', 1)).toBe('1')
    expect(sanitizeDigits('6a.7８', 2)).toBe('67')
    expect(sanitizeDigits('05', 2)).toBe('5')
    // 数字の無い文字は 0(旧の Number('') と同じ)。空にしたときだけ空
    expect(sanitizeDigits('時', 2)).toBe('0')
    expect(sanitizeDigits('', 2)).toBe('')
  })

  test('TC-64-06: 英名の全角の英数字を半角にし、ほかの文字は変えない', () => {
    expect(toHalfWidthAlphanumeric('ＧＩＮ　４０ ＆ Tonic')).toBe('GIN　40 ＆ Tonic')
  })
})
