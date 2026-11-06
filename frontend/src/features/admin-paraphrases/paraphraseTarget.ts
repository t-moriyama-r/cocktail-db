import type { components } from '../../api/schema'

type SearchFormNode = components['schemas']['SearchFormNodeResource']
type ParaphraseResource = components['schemas']['ParaphraseResource']

/** 言い換えの変換後の欄に入れる名前(converted・middle)。ミドルが無いときは空 */
export type ParaphraseTargetNames = { converted: string; middle: string }

/** 木から選んでいるもの。銘柄は、所属するノード(配下の銘柄を出しているノード)と一緒に持つ */
export type PickedTarget = { nodeId: number; brandId: number | null }

type NamedNode = Pick<SearchFormNode, 'id' | 'name'>

/** 木の各ノードの、根からの道のり(根が先頭で、そのノードが末尾) */
export function indexNodePaths(roots: readonly SearchFormNode[]): ReadonlyMap<number, readonly NamedNode[]> {
  const paths = new Map<number, readonly NamedNode[]>()
  const visit = (node: SearchFormNode, ancestors: readonly NamedNode[]) => {
    const path = [...ancestors, { id: node.id, name: node.name }]
    paths.set(node.id, path)
    node.children.forEach((child) => visit(child, path))
  }
  roots.forEach((root) => visit(root, []))
  return paths
}

/**
 * 木で選んだノードを、変換後の名前にする(docs/decisions/paraphrase-target.md の解決の決まりに合わせる)。
 *
 * - 根のすぐ下のノード(旧のカテゴリ): 変換後にその名前を入れ、ミドルは空(API は根のすぐ下のノードを最初に探す)
 * - それより深いノード(旧のミドル): 変換後に根のすぐ下の祖先の名前、ミドルにそのノードの名前を入れる。
 *   ミドルを空にして変換後だけにすると、同じ名前の銘柄があればそちらに解決される(API は銘柄を深いノードより先に探す)ため
 * - 根(旧の種別)は読み替え先にできない(API が根を探さない)ので null
 */
export function targetNamesOfNode(path: readonly NamedNode[]): ParaphraseTargetNames | null {
  const [, category] = path
  if (category === undefined) {
    return null
  }
  const node = path[path.length - 1]
  return node.id === category.id
    ? { converted: category.name, middle: '' }
    : { converted: category.name, middle: node.name }
}

/** 銘柄を選んだときの変換後の名前。銘柄はミドルを持てない(API が 422 にする)ので、ミドルは空 */
export const targetNamesOfBrand = (brand: { name: string }): ParaphraseTargetNames => ({
  converted: brand.name,
  middle: '',
})

/** 保存してある言い換えの、木で選んでいるもの(解決した先)。解決できない語は何も選ばない */
export function pickedTargetOf({ resolved }: Pick<ParaphraseResource, 'resolved'>): PickedTarget | null {
  const node = resolved?.path[resolved.path.length - 1]
  return node === undefined ? null : { nodeId: node.id, brandId: resolved?.brand?.id ?? null }
}

/** 一覧に出す、解決した先のパンくず(根からのノードの名前と、銘柄ならその名前を「/」でつなぐ)。解決できなければ null */
export function resolvedLabel({ resolved }: Pick<ParaphraseResource, 'resolved'>): string | null {
  if (resolved === null) {
    return null
  }
  const names = resolved.path.map((node) => node.name)
  return (resolved.brand === null ? names : [...names, resolved.brand.name]).join(' / ')
}
