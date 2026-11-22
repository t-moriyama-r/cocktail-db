import { describe, expect, test } from 'vitest'
import { parentNodes } from './parentNodes'

const root = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const rum = { id: 7, name: 'ラム', name_eng: 'Rum' }
const white = { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' }
const cuban = { id: 700, name: 'キューバンホワイトラム', name_eng: 'Cuban White Rum' }
const havana = { id: 7000, name: 'ハバナ', name_eng: 'Havana' }

describe('parentNodes', () => {
  test('TC-52-02: いちばん上の段のノード(親が根)は、親の段を出さない', () => {
    expect(parentNodes([root, rum])).toEqual([])
  })

  test('TC-52-03: 2 段目のノードは、いちばん上の段だけ', () => {
    expect(parentNodes([root, rum, white])).toEqual([rum])
  })

  test('TC-52-04: 3 段目より深いノードは、いちばん上の段とすぐ上の親だけで、間の段は省く', () => {
    expect(parentNodes([root, rum, white, cuban])).toEqual([rum, white])
    expect(parentNodes([root, rum, white, cuban, havana])).toEqual([rum, cuban])
  })
})
