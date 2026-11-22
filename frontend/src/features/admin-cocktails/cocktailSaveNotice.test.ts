import { describe, expect, test } from 'vitest'
import { cocktailSaveNotice, readCocktailSaveNotice } from './cocktailSaveNotice'

const WARNING = { alias: 'G&T', message: '「ジントニック」の別名と同じです', cocktailId: 1, cocktailName: 'ジントニック' }

describe('保存の後の別名の警告', () => {
  test('TC-83-14: 履歴の state は、作った知らせだけを読み、警告が無い・形の合わない値なら何も出さない', () => {
    const notice = cocktailSaveNotice('E2E の下書き', [WARNING])
    expect(readCocktailSaveNotice(notice)).toEqual({ name: 'E2E の下書き', warnings: [WARNING] })

    expect(cocktailSaveNotice('E2E の下書き', [])).toBeNull()
    expect(readCocktailSaveNotice(null)).toBeNull()
    expect(readCocktailSaveNotice('「x」を保存しました')).toBeNull()
    expect(readCocktailSaveNotice({ notice: '「x」を保存しました' })).toBeNull()
    expect(readCocktailSaveNotice({ cocktailSaveNotice: { name: 'x', warnings: [] } })).toBeNull()
    expect(readCocktailSaveNotice({ cocktailSaveNotice: { name: 1, warnings: [WARNING] } })).toBeNull()
    expect(readCocktailSaveNotice({ cocktailSaveNotice: { name: 'x', warnings: [{ ...WARNING, cocktailId: '1' }] } })).toBeNull()
    expect(readCocktailSaveNotice({ cocktailSaveNotice: { name: 'x', warnings: [{ ...WARNING, cocktailId: 1.5 }] } })).toBeNull()
    expect(readCocktailSaveNotice({ cocktailSaveNotice: { name: 'x', warnings: 'G&T' } })).toBeNull()
  })
})
