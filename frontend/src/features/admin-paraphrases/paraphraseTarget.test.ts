import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import {
  indexNodePaths,
  pickedTargetOf,
  resolvedLabel,
  targetNamesOfBrand,
  targetNamesOfNode,
} from './paraphraseTarget'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

const node = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

// 蒸留酒 > ジン > ドライジン > ロンドンドライジン(E2eSeeder と同じ形)
const ROOTS = [node(1, '蒸留酒', [node(2, 'ジン', [node(3, 'ドライジン', [node(4, 'ロンドンドライジン')])])])]
const paths = indexNodePaths(ROOTS)

describe('targetNamesOfNode', () => {
  test('TC-67-22: 根は読み替え先にできないので null、根のすぐ下はその名前だけ、それより深い段は根のすぐ下の祖先とミドル', () => {
    expect(targetNamesOfNode(paths.get(1) ?? [])).toBeNull()
    expect(targetNamesOfNode(paths.get(2) ?? [])).toEqual({ converted: 'ジン', middle: '' })
    expect(targetNamesOfNode(paths.get(3) ?? [])).toEqual({ converted: 'ジン', middle: 'ドライジン' })
    expect(targetNamesOfNode(paths.get(4) ?? [])).toEqual({ converted: 'ジン', middle: 'ロンドンドライジン' })
    expect(targetNamesOfNode([])).toBeNull()
  })

  test('TC-67-23: 銘柄は、変換後に銘柄の名前を入れ、ミドルは空', () => {
    expect(targetNamesOfBrand({ name: 'ビーフィーター 40度' })).toEqual({
      converted: 'ビーフィーター 40度',
      middle: '',
    })
  })
})

describe('保存してある語の解決した先', () => {
  const ref = (id: number, name: string) => ({ id, name, name_eng: name })

  test('TC-67-24: ノードに解決した語は末尾のノードを選び、銘柄に解決した語は所属のノードと銘柄を選ぶ。解決できなければ選ばない', () => {
    expect(pickedTargetOf({ resolved: { path: [ref(1, '蒸留酒'), ref(2, 'ジン')], brand: null } })).toEqual({
      nodeId: 2,
      brandId: null,
    })
    expect(
      pickedTargetOf({ resolved: { path: [ref(1, '蒸留酒'), ref(2, 'ジン')], brand: ref(40, 'ビーフィーター 40度') } }),
    ).toEqual({ nodeId: 2, brandId: 40 })
    expect(pickedTargetOf({ resolved: null })).toBeNull()
  })

  test('TC-67-25: 一覧のパンくずは、根からのノードの名前(銘柄ならその後ろに銘柄)を「 / 」でつなぎ、解決できなければ null', () => {
    expect(resolvedLabel({ resolved: { path: [ref(1, '蒸留酒'), ref(2, 'ジン')], brand: null } })).toBe('蒸留酒 / ジン')
    expect(
      resolvedLabel({ resolved: { path: [ref(1, '蒸留酒'), ref(2, 'ジン')], brand: ref(40, 'ビーフィーター 40度') } }),
    ).toBe('蒸留酒 / ジン / ビーフィーター 40度')
    expect(resolvedLabel({ resolved: null })).toBeNull()
  })
})
