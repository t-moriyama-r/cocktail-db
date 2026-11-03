import { MemoryRouter } from 'react-router'
import Affiliate from './Affiliate'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。この部品を使う詳細の画面がまだ無いので、ここで出す。
 * API は本物を呼ぶ(開発サーバーが /api を Laravel に流す)。ノード・銘柄は URL のクエリ(?category_id=12&brand_id=345)で渡す。
 * 上に本文の幅の欄、下に画像の欄の幅(旧の is_photo_space)の欄を出す
 */
export default function AffiliatePreview() {
  return (
    <MemoryRouter>
      <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <section>
          <h2>本文の幅</h2>
          <Affiliate categoryId={categoryId} brandId={brandId} />
        </section>
        <section style={{ maxWidth: '400px' }}>
          <h2>画像の欄(inImage)</h2>
          <Affiliate categoryId={categoryId} brandId={brandId} inImage />
        </section>
      </main>
    </MemoryRouter>
  )
}

const query = new URLSearchParams(window.location.search)

const categoryId = Number(query.get('category_id') ?? 1)

const brandId = query.get('brand_id') === null ? null : Number(query.get('brand_id'))
