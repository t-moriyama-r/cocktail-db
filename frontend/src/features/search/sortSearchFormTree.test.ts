import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import { sortSearchFormTree } from './sortSearchFormTree'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

/** GET /api/search-form の木の 1 ノード。指定しないものは、メジャー・役割なし・子なし */
const node = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

const namesOf = (nodes: readonly SearchFormNode[]) => nodes.map((n) => n.name)

describe('sortSearchFormTree', () => {
  test('TC-400-01: 根のすぐ下(旧のカテゴリ)が、名前の五十音順に並ぶ', () => {
    const tree = [node(1, 'ウイスキー', [node(11, 'バーボン'), node(12, 'スコッチ'), node(13, 'アイリッシュ')])]

    expect(namesOf(sortSearchFormTree(tree)[0].children)).toEqual(['アイリッシュ', 'スコッチ', 'バーボン'])
  })

  test('TC-400-02: カタカナとひらがなをそろえて比べる', () => {
    // そろえないと、ひらがな(U+3040〜)がカタカナ(U+30A0〜)より前に来て「うめ酒」が先になる
    const tree = [node(1, '混成酒', [node(11, 'うめ酒'), node(12, 'アマレット')])]

    expect(namesOf(sortSearchFormTree(tree)[0].children)).toEqual(['アマレット', 'うめ酒'])
  })

  test('TC-400-03: 全角の英数字は半角にそろえて比べる', () => {
    // そろえないと、全角の「Ａ」(U+FF21)がカタカナより後ろに来る
    const tree = [node(1, 'その他', [node(11, 'ジン'), node(12, 'Ａ・Ｂ'), node(13, '１号')])]

    expect(namesOf(sortSearchFormTree(tree)[0].children)).toEqual(['１号', 'Ａ・Ｂ', 'ジン'])
  })

  test('TC-400-04: 大文字と小文字はそろえず、文字コードの順で比べる(旧と同じ)', () => {
    const tree = [node(1, 'その他', [node(11, 'bitters'), node(12, 'Cola')])]

    expect(namesOf(sortSearchFormTree(tree)[0].children)).toEqual(['Cola', 'bitters'])
  })

  test('TC-400-05: 根(旧の種別)は API の順のまま', () => {
    const tree = [node(1, '蒸留酒'), node(2, '醸造酒'), node(3, '混成酒'), node(4, 'ソフトドリンク'), node(5, 'その他')]

    expect(namesOf(sortSearchFormTree(tree))).toEqual(['蒸留酒', '醸造酒', '混成酒', 'ソフトドリンク', 'その他'])
  })

  test('TC-400-06: 根の子より深い段(旧のミドルと、その下)は API の順のまま', () => {
    const deep = node(111, 'ラム', [node(1112, 'ホワイト'), node(1111, 'ダーク')])
    const tree = [node(1, '蒸留酒', [node(11, 'ラム', [deep, node(112, 'アネホ'), node(113, 'ゴールド')])])]

    const [rum] = sortSearchFormTree(tree)[0].children
    expect(namesOf(rum.children)).toEqual(['ラム', 'アネホ', 'ゴールド'])
    expect(namesOf(rum.children[0].children)).toEqual(['ホワイト', 'ダーク'])
  })

  test('TC-400-07: そろえた名前が同じなら、API の順のまま', () => {
    const tree = [node(1, '蒸留酒', [node(12, 'らむ'), node(11, 'ラム'), node(13, 'ジン')])]

    expect(sortSearchFormTree(tree)[0].children.map((n) => n.id)).toEqual([13, 12, 11])
  })

  test('TC-400-08: 受け取った木は書き換えず、ノードの中身(ID・メジャーか・役割)はそのまま返す', () => {
    const tree: SearchFormNode[] = [
      { ...node(1, 'その他', [node(11, 'ラム'), { ...node(12, 'ジン', [node(121, 'ドライジン')]), is_major: false }]), root_role: 'other' },
    ]
    const before = structuredClone(tree)

    const sorted = sortSearchFormTree(tree)

    expect(tree).toEqual(before)
    expect(sorted[0]).toEqual({ ...before[0], children: [before[0].children[1], before[0].children[0]] })
  })

  test('TC-400-09: 木が空・子の無い根でも例外にならない', () => {
    expect(sortSearchFormTree([])).toEqual([])
    expect(sortSearchFormTree([node(1, 'その他')])).toEqual([node(1, 'その他')])
  })
})
