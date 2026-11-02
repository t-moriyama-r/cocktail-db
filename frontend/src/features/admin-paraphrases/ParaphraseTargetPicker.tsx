import { useCallback, useId, useMemo, type KeyboardEvent } from 'react'
import type { components } from '../../api/schema'
import { useApi } from '../../api/useApi'
import InlineApiView from '../../components/ApiView/InlineApiView'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { fetchCategoryBrands, fetchCategoryTree } from './adminParaphraseApi'
import {
  indexNodePaths,
  targetNamesOfBrand,
  targetNamesOfNode,
  type ParaphraseTargetNames,
  type PickedTarget,
} from './paraphraseTarget'
import styles from './AdminParaphrases.module.css'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

type Props = {
  /** 木で選んでいるもの。手で名前を書き換えたら null(選んでいない) */
  picked: PickedTarget | null
  /** 選んだもの(ノードか銘柄)と、変換後・ミドルの欄に入れる名前を返す */
  onPick: (picked: PickedTarget, names: ParaphraseTargetNames) => void
}

/**
 * 言い換えの変換後を、木から選ぶ欄(#67)。木の部品(#297)の単一選択でノードを選び、選んだノードの配下の銘柄も選べる。
 * 選ぶと、変換後・ミドルの欄に名前が入る(表は名前のまま持つ。#33)。根(旧の種別)は読み替え先にできないので見出しにする
 */
export default function ParaphraseTargetPicker({ picked, onPick }: Props) {
  const { state, retry } = useApi(fetchCategoryTree)

  // この欄はフォームの中にあるので、木の絞り込みの欄などで Enter を押すと、ブラウザがフォームを送ってしまう。
  // 絞り込もうとしただけで保存しないよう、この欄の入力での Enter は止める
  const preventSubmit = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Enter' && event.target instanceof HTMLInputElement) {
      event.preventDefault()
    }
  }

  return (
    <div className={styles.picker} onKeyDown={preventSubmit}>
      <InlineApiView state={state} onRetry={retry}>
        {({ categories }) => <TreeChoices roots={categories} picked={picked} onPick={onPick} />}
      </InlineApiView>
    </div>
  )
}

function TreeChoices({ roots, picked, onPick }: Props & { roots: readonly SearchFormNode[] }) {
  const nodes = useMemo(() => roots.map(toCategoryTreeNode), [roots])
  const paths = useMemo(() => indexNodePaths(roots), [roots])
  const appearance = useMemo<CategoryTreeAppearance>(() => {
    const rootIds = new Set(roots.map((root) => root.id))
    return { isHeading: (node) => rootIds.has(node.id) }
  }, [roots])

  const pickNode = (node: CategoryTreeNode) => {
    const names = targetNamesOfNode(paths.get(node.id) ?? [])
    if (names !== null) {
      onPick({ nodeId: node.id, brandId: null }, names)
    }
  }

  return (
    <>
      <CategoryTree
        label="変換後を選ぶ木"
        nodes={nodes}
        selection={{
          mode: 'single',
          selectedId: picked?.nodeId ?? null,
          onSelect: pickNode,
          isSelectable: (node) => targetNamesOfNode(paths.get(node.id) ?? []) !== null,
        }}
        appearance={appearance}
      />
      {picked !== null && paths.has(picked.nodeId) && (
        <BrandChoices
          nodeId={picked.nodeId}
          brandId={picked.brandId}
          onPick={(brand) => onPick({ nodeId: picked.nodeId, brandId: brand.id }, targetNamesOfBrand(brand))}
        />
      )}
    </>
  )
}

type BrandChoicesProps = {
  nodeId: number
  brandId: number | null
  onPick: (brand: { id: number; name: string }) => void
}

/** 選んだノードの配下すべての銘柄を、ラジオボタンで出す。銘柄の無いノードでは、無いと出す */
function BrandChoices({ nodeId, brandId, onPick }: BrandChoicesProps) {
  const request = useCallback(() => fetchCategoryBrands(nodeId), [nodeId])
  const { state, retry } = useApi(request)
  const name = useId()

  return (
    <fieldset className={styles.brands}>
      <legend className={styles.label}>選んだノードの配下の銘柄</legend>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) =>
          data.brands.length === 0 ? (
            <p className={styles.hint}>銘柄はありません</p>
          ) : (
            <ul className={styles.brandList}>
              {data.brands.map((brand) => (
                <li key={brand.id}>
                  <label>
                    <input type="radio" name={name} checked={brandId === brand.id} onChange={() => onPick(brand)} />
                    {brand.name}
                  </label>
                </li>
              ))}
            </ul>
          )
        }
      </InlineApiView>
    </fieldset>
  )
}

/** 検索フォームの API のノードを、木の部品が受け取る形にする(この API は英名を返さない)。メジャーでないノードも出す */
function toCategoryTreeNode(node: SearchFormNode): CategoryTreeNode {
  return {
    id: node.id,
    name: node.name,
    name_eng: null,
    is_major: node.is_major,
    children: node.children.map(toCategoryTreeNode),
  }
}
