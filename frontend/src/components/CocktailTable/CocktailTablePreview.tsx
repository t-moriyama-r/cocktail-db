import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import CocktailHitCount from './CocktailHitCount'
import CocktailTable from './CocktailTable'
import type { CocktailSort } from './CocktailTable.types'
import { buildCocktailRow as row } from './buildCocktailRow'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。手元で並べ替える表と、並びを外から渡す表を並べる。
 * 行は、甘さの境界・評価の有無・動画の有無・6 つの技法がそろうよう選んだ、旧のデータにあるカクテル。
 * 名前・必須・甘さ・評価・度数・技法・動画の有無は旧のデータの値で、ベース・英名・動画の ID・更新日時は見本の値
 */
export default function CocktailTablePreview() {
  const [serverSort, setServerSort] = useState<CocktailSort | null>(null)

  return (
    <MemoryRouter>
      <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <section>
          <CocktailHitCount heading="カクテル検索結果" count={SAMPLE_ROWS.length} />
          <CocktailTable cocktails={SAMPLE_ROWS} sort={{ mode: 'local' }} label="カクテル検索結果" />
        </section>
        <section>
          <h2>並びを外から渡す形(押すと次の並びを受け取って表示するだけ)</h2>
          <p data-testid="server-sort">渡された並び: {serverSort ? `${serverSort.key} ${serverSort.isAsc ? '昇順' : '降順'}` : 'なし'}</p>
          <CocktailTable
            cocktails={SAMPLE_ROWS.slice(0, 3)}
            sort={{ mode: 'controlled', sort: serverSort, onSortChange: setServerSort }}
            label="検索結果"
          />
        </section>
        <section>
          <CocktailHitCount heading="カクテル検索結果" count={0} />
          <CocktailTable cocktails={[]} sort={{ mode: 'local' }} label="0 件" />
        </section>
      </main>
    </MemoryRouter>
  )
}

const SAMPLE_ROWS = [
  row({ id: 29, name: 'デビル', name_eng: 'Devil', base: { id: 4, name: 'ブランデー', name_eng: 'Brandy' }, method: { id: 3, name: 'シェイク' }, recipe_count: 2, strength: 33.7, sweet_degree: -7, rate: 4, youtube: 'x9gaP5Q8lc4', updated_at: '2021-03-01T00:00:00Z' }),
  row({ id: 63, name: 'マルガリータ', name_eng: 'Margarita', base: { id: 3, name: 'テキーラ', name_eng: 'Tequila' }, method: { id: 3, name: 'シェイク' }, recipe_count: 4, strength: 30, sweet_degree: -6, rate: 4, youtube: 'xa8mIHLpvNM', updated_at: '2021-06-01T00:00:00Z' }),
  row({ id: 159, name: 'ゴッドファーザー', name_eng: 'Godfather', base: { id: 5, name: 'ウイスキー', name_eng: 'Whisky' }, method: { id: 1, name: 'ビルド' }, recipe_count: 2, strength: 39.3, sweet_degree: -4, rate: 4, youtube: '3RO9fyrDJFw', updated_at: '2020-11-01T00:00:00Z' }),
  row({ id: 6536, name: '苦尽甘来', name_eng: 'Kujin Kanrai', base: { id: 9, name: 'リキュール', name_eng: 'Liqueur' }, method: { id: 1, name: 'ビルド' }, recipe_count: 3, strength: 5.8, sweet_degree: -1, rate: 3, youtube: null }),
  row({ id: 12, name: 'バーバラ', name_eng: 'Barbara', base: { id: 2, name: 'ウォッカ', name_eng: 'Vodka' }, method: { id: 3, name: 'シェイク' }, recipe_count: 3, strength: 26, sweet_degree: 1, rate: 2, youtube: null }),
  row({ id: 5042, name: 'フレンチ・カクタス', name_eng: 'French Cactus', base: { id: 3, name: 'テキーラ', name_eng: 'Tequila' }, method: { id: 2, name: 'ステア' }, recipe_count: 2, strength: 40, sweet_degree: 1, rate: 4, youtube: null }),
  row({ id: 15, name: '針葉樹林', name_eng: 'Shinyoujurin', base: { id: 9, name: 'リキュール', name_eng: 'Liqueur' }, method: { id: 1, name: 'ビルド' }, recipe_count: 3, strength: 8.3, sweet_degree: 2, rate: 5, youtube: 'v15', updated_at: '2022-01-01T00:00:00Z' }),
  row({ id: 71, name: 'フローズン・マルガリータ', name_eng: 'Frozen Margarita', base: { id: 3, name: 'テキーラ', name_eng: 'Tequila' }, method: { id: 4, name: 'ブレンド' }, recipe_count: 4, strength: 10, sweet_degree: null, rate: null, youtube: null }),
  row({ id: 6490, name: 'コカリタス', name_eng: 'Cocaritas', base: { id: 3, name: 'テキーラ', name_eng: 'Tequila' }, method: { id: 5, name: 'プース・カフェ' }, recipe_count: 2, strength: 51.3, sweet_degree: -7, rate: 3, youtube: 'v6490', updated_at: '2023-01-01T00:00:00Z' }),
  row({ id: 264, name: 'ベイリーズコーヒー', name_eng: "Baileys Coffee", base: { id: 9, name: 'リキュール', name_eng: 'Liqueur' }, method: { id: 6, name: 'ホット' }, recipe_count: 2, strength: 2.4, sweet_degree: 2, rate: 5, youtube: 'v264', updated_at: '2020-02-01T00:00:00Z' }),
  row({ id: 13, name: '照葉樹林', name_eng: 'Shouyoujurin', base: { id: 9, name: 'リキュール', name_eng: 'Liqueur' }, method: { id: 1, name: 'ビルド' }, recipe_count: 2, strength: 8.3, sweet_degree: 4, rate: 4, youtube: 'v13', updated_at: '2021-09-01T00:00:00Z' }),
]
