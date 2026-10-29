import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import RandomCocktails from './RandomCocktails'
import type { RandomCocktailsOrigin } from './RandomCocktails.types'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。この部品を使う詳細の画面がまだ無いので、ここで起点と件数を選んで出す。
 * API は本物を呼ぶ(開発サーバーが /api を Laravel に流す)。起点と件数の初めの値は URL のクエリ(?kind=category&id=7&limit=5)で渡せる
 */
export default function RandomCocktailsPreview() {
  const [origin, setOrigin] = useState<RandomCocktailsOrigin>(initialOrigin)
  const [limit, setLimit] = useState(initialLimit)

  return (
    <MemoryRouter>
      <main style={{ padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <form style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-3)' }} onSubmit={(e) => e.preventDefault()}>
          <label>
            起点{' '}
            <select value={origin.kind} onChange={(e) => setOrigin({ ...origin, kind: e.target.value as RandomCocktailsOrigin['kind'] })}>
              {KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {kind}
                </option>
              ))}
            </select>
          </label>
          <label>
            ID <input type="number" min={1} value={origin.id} onChange={(e) => setOrigin({ ...origin, id: Number(e.target.value) })} />
          </label>
          <label>
            件数 <input type="number" min={1} max={50} value={limit} onChange={(e) => setLimit(Number(e.target.value))} />
          </label>
        </form>
        <RandomCocktails origin={origin} limit={limit} />
      </main>
    </MemoryRouter>
  )
}

const KINDS: readonly RandomCocktailsOrigin['kind'][] = ['cocktail', 'category', 'brand', 'base', 'method', 'glass']

const query = new URLSearchParams(window.location.search)

const initialOrigin: RandomCocktailsOrigin = {
  kind: KINDS.find((kind) => kind === query.get('kind')) ?? 'category',
  id: Number(query.get('id') ?? 1),
}

const initialLimit = Number(query.get('limit') ?? 5)
