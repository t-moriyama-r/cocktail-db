import { expect, test } from 'vitest'
import { toComparableKana } from './toComparableKana'

test('TC-298-27: カタカナをひらがなに、全角の英数字を半角にそろえ、大文字と小文字はそろえない', () => {
  expect(toComparableKana('マティーニＸＹＺ１２３abc')).toBe('まてぃーにXYZ123abc')
})
