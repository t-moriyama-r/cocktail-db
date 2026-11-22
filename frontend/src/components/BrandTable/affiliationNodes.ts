import type { BrandTableNode } from './BrandTable.types'

/**
 * 所属の列に出す段。いちばん上の段(大元の直下)と、銘柄の所属するノード(末尾)だけを選び、間のノードは省く
 * (docs/decisions/public-detail.md の決まったこと 3)。所属するノードがいちばん上の段そのものなら、その 1 段だけ。
 * パンくずが大元だけのときは、省くと何も出なくなるので大元を出す
 */
export function affiliationNodes(path: readonly BrandTableNode[]): BrandTableNode[] {
  if (path.length <= 1) {
    return [...path]
  }
  const top = path[1]
  const last = path[path.length - 1]
  return top.id === last.id ? [top] : [top, last]
}
