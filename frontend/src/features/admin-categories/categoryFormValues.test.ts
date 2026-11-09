import { describe, expect, test } from 'vitest'
import { savedParentOf } from './categoryFormValues'

describe('保存する親(savedParentOf)', () => {
  test('TC-705-01: 根なら、前に選んでいた親があっても根どうし(parentId: null)', () => {
    expect(savedParentOf({ isRoot: true, parentId: null })).toEqual({ parentId: null })
    expect(savedParentOf({ isRoot: true, parentId: 5 })).toEqual({ parentId: null })
  })

  test('TC-705-02: 根でなく親があれば、その親', () => {
    expect(savedParentOf({ isRoot: false, parentId: 5 })).toEqual({ parentId: 5 })
  })

  test('TC-705-03: 根でなく親をまだ選んでいなければ、決まらない(null)', () => {
    expect(savedParentOf({ isRoot: false, parentId: null })).toBeNull()
  })
})
