import { MemoryRouter } from 'react-router'
import BrandTable from './BrandTable'
import type { BrandTableNode } from './BrandTable.types'
import { buildBrandRow as row } from './buildBrandRow'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。
 * 行は、所属の段数(1・2・3 段)・度数の有無・動画の有無・英名の有無がそろうよう選んだ見本の値で、旧のデータの値ではない
 */
export default function BrandTablePreview() {
  return (
    <MemoryRouter>
      <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <section>
          <h2>銘柄の一覧</h2>
          <BrandTable brands={SAMPLE_ROWS} label="銘柄の一覧" />
        </section>
        <section>
          <h2>0 件</h2>
          <BrandTable brands={[]} label="0 件" />
        </section>
      </main>
    </MemoryRouter>
  )
}

const SPIRITS: BrandTableNode = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const LIQUEUR_ROOT: BrandTableNode = { id: 2, name: '混成酒', name_eng: 'Liqueurs' }
const RUM: BrandTableNode = { id: 7, name: 'ラム', name_eng: 'Rum' }
const CUBAN_RUM: BrandTableNode = { id: 30, name: 'キューバのラム', name_eng: 'Cuban Rum' }
const WHITE_RUM: BrandTableNode = { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' }
const GIN: BrandTableNode = { id: 8, name: 'ジン', name_eng: 'Gin' }
const DRY_GIN: BrandTableNode = { id: 80, name: 'ドライ・ジン', name_eng: 'Dry Gin' }
const CASSIS: BrandTableNode = { id: 9, name: 'カシス・リキュール', name_eng: 'Cassis Liqueur' }

const SAMPLE_ROWS = [
  row({ id: 101, name: 'バカルディ スペリオール', name_eng: 'Bacardi Superior', path: [SPIRITS, RUM, CUBAN_RUM, WHITE_RUM], strength: 40, youtube: 'x9gaP5Q8lc4', updated_at: '2021-03-01T00:00:00Z' }),
  row({ id: 102, name: 'マイヤーズ', name_eng: 'Myers’s', path: [SPIRITS, RUM], strength: 40, youtube: null }),
  row({ id: 103, name: 'ビーフィーター', name_eng: 'Beefeater', path: [SPIRITS, GIN, DRY_GIN], strength: 47, youtube: 'xa8mIHLpvNM', updated_at: '2022-06-01T00:00:00Z' }),
  row({ id: 104, name: 'ルジェ クレーム・ド・カシス', name_eng: 'Lejay Creme de Cassis', path: [LIQUEUR_ROOT, CASSIS], strength: 20, youtube: null }),
  row({ id: 105, name: '自家製のシロップ', name_eng: '', path: [LIQUEUR_ROOT, CASSIS], strength: null, youtube: null }),
]
