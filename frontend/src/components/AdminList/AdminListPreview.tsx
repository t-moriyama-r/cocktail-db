import { MemoryRouter } from 'react-router'
import AdminList, { type AdminListPage, type AdminListQuery } from './AdminList'
import AdminListLink from './AdminListLink'

type SampleItem = { id: number; name: string; name_eng: string | null }

/** 1 ページの件数(管理 API と同じ 50 件。#284 の 6) */
const PER_PAGE = 50

const BASES = [
  ['ジン', 'Gin'],
  ['ラム', 'Rum'],
  ['ウォッカ', 'Vodka'],
  ['テキーラ', 'Tequila'],
  ['ウイスキー', 'Whisky'],
  ['ブランデー', 'Brandy'],
] as const

/**
 * 見本の行。ページ送りが出るよう 3 ページ分(120 件)を作り、英名の無い行と、長い名前の行(狭い幅で折り返すか)も混ぜる。
 * 旧のデータの値ではない
 */
const SAMPLE_ITEMS: SampleItem[] = [
  { id: 1, name: 'アプリコット・ブランデーとピーチ・リキュールとオレンジ・ジュースのとても長い名前のカクテル', name_eng: 'A Very Long Cocktail Name With Apricot Brandy, Peach Liqueur And Orange Juice' },
  { id: 2, name: '自家製のカクテル', name_eng: null },
  ...Array.from({ length: 118 }, (_, i) => {
    const [name, nameEng] = BASES[i % BASES.length]
    const no = String(i + 1).padStart(3, '0')
    return { id: i + 3, name: `${name}のカクテル${no}`, name_eng: `${nameEng} Cocktail ${no}` }
  }),
]

/** 管理 API の代わり。名前・英名の部分一致で絞り、50 件ずつ返す(見本なので、かな・全角はそろえない) */
const pageOf =
  (items: SampleItem[]) =>
  async ({ q, page }: AdminListQuery) => {
    const hits =
      q === undefined
        ? items
        : items.filter((item) => item.name.includes(q) || (item.name_eng ?? '').toLowerCase().includes(q.toLowerCase()))
    const body: AdminListPage<SampleItem> = {
      data: hits.slice((page - 1) * PER_PAGE, page * PER_PAGE),
      meta: { current_page: page, last_page: Math.max(1, Math.ceil(hits.length / PER_PAGE)) },
    }
    return { data: body, response: new Response(null, { status: 200 }) }
  }

const loadSample = pageOf(SAMPLE_ITEMS)
const loadEmpty = pageOf([])
const loadChildren = pageOf(SAMPLE_ITEMS.slice(2, 5))

const renderItem = (item: SampleItem) => <AdminListLink to={`/admin/cocktails/${item.id}`} name={item.name} nameEng={item.name_eng} />
const itemKey = (item: SampleItem) => item.id

/** 部品を画面で確かめるための見本(preview.html から開く) */
export default function AdminListPreview() {
  return (
    <MemoryRouter>
      <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <section>
          <h2>一覧(絞り込みとページ送り。120 件)</h2>
          <AdminList load={loadSample} itemKey={itemKey} renderItem={renderItem} />
        </section>
        <section>
          <h2>0 件</h2>
          <AdminList load={loadEmpty} itemKey={itemKey} renderItem={renderItem} />
        </section>
        <section>
          <h2>絞り込みの欄を出さない使い方(3 件)</h2>
          <AdminList load={loadChildren} itemKey={itemKey} renderItem={renderItem} searchable={false} />
        </section>
      </main>
    </MemoryRouter>
  )
}
