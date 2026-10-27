import { describe, expect, test } from 'vitest'
import { rateStars } from './rateStars'
import { strengthLabel } from './strengthLabel'
import { sweetDegreeLabel } from './sweetDegreeLabel'

describe('甘さの言葉', () => {
  test.each([
    [-10, '辛口'],
    [-7, '辛口'],
    [-6, '中辛'],
    [-4, '中辛'],
    [-3, '辛め'],
    [-1, '辛め'],
    [0, '普通'],
    [1, '普通'],
    [2, '甘め'],
    [3, '甘め'],
    [4, '甘口'],
    [10, '甘口'],
    [99, '-'],
    [null, '-'],
  ] as const)('TC-298-01: 甘さ %s は「%s」', (degree, label) => {
    expect(sweetDegreeLabel(degree)).toBe(label)
  })
})

describe('評価の ★', () => {
  test.each([
    [0, '-'],
    [null, '-'],
    [1, '★'],
    [5, '★★★★★'],
  ] as const)('TC-298-02: 評価 %s は「%s」', (rate, label) => {
    expect(rateStars(rate)).toBe(label)
  })
})

describe('度数', () => {
  test.each([
    [30, '30.0'],
    [33.3, '33.3'],
    [5.75, '5.8'],
    [0, '0.0'],
    [null, '0.0'],
  ] as const)('TC-298-03: 度数 %s は「%s」(小数 1 桁。無いときは 0.0)', (strength, label) => {
    expect(strengthLabel(strength)).toBe(label)
  })
})
