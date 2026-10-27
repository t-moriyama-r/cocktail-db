import { buildCategoryTreeNode as buildNode } from './buildCategoryTreeNode'
import type { CategoryTreeNode } from './CategoryTree.types'

/**
 * テストで使う木。「ビーフィーター」が深さ 5(根を 1 段目と数える)。
 *
 * 蒸留酒 > ラム > ホワイトラム・ダークラム
 *        > ジン > ドライジン > ロンドンドライジン > ビーフィーター
 * ソフトドリンク > ジュース
 */
export function buildTestTree(): CategoryTreeNode[] {
  return [
    buildNode({
      id: 1,
      name: '蒸留酒',
      name_eng: 'Spirits',
      children: [
        buildNode({
          id: 10,
          name: 'ラム',
          name_eng: 'Rum',
          children: [
            buildNode({ id: 100, name: 'ホワイトラム', name_eng: 'White Rum' }),
            buildNode({ id: 101, name: 'ダークラム', name_eng: 'Dark Rum' }),
          ],
        }),
        buildNode({
          id: 11,
          name: 'ジン',
          name_eng: 'Gin',
          children: [
            buildNode({
              id: 110,
              name: 'ドライジン',
              name_eng: 'Dry Gin',
              children: [
                buildNode({
                  id: 1100,
                  name: 'ロンドンドライジン',
                  name_eng: 'London Dry Gin',
                  children: [buildNode({ id: 11000, name: 'ビーフィーター', name_eng: 'Beefeater' })],
                }),
              ],
            }),
          ],
        }),
      ],
    }),
    buildNode({
      id: 2,
      name: 'ソフトドリンク',
      name_eng: 'Soft Drink',
      children: [buildNode({ id: 20, name: 'ジュース' })],
    }),
  ]
}
