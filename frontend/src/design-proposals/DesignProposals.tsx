import DetailScreen from './DetailScreen'
import { PROPOSALS, SCREENS, toProposalId, toScreenId, type ScreenId } from './proposals'
import ResultScreen from './ResultScreen'
import SiteFrame from './SiteFrame'
import TopScreen from './TopScreen'
import styles from './DesignProposals.module.css'

/**
 * 見た目の案を見比べるための試作の画面(開発サーバーの /spa/design.html だけで開く。本番のビルドには入らない)。
 * URL の `proposal`(a・b・c)と `screen`(top・detail・result)で、どの案のどの画面を出すかを選ぶ。
 * 案の違いはトークン(tokens.css)だけにあり、画面の部品は 3 案で同じものを使う。
 * `bar=0` を付けると切り替えの帯を消す(スクリーンショット用)。
 */
export default function DesignProposals({ search }: { search: string }) {
  const params = new URLSearchParams(search)
  const proposal = toProposalId(params.get('proposal'))
  const screen = toScreenId(params.get('screen'))
  const showBar = params.get('bar') !== '0'

  return (
    <div data-proposal={proposal}>
      {showBar && (
        <nav className={styles.bar} aria-label="案と画面の切り替え">
          <ul>
            {PROPOSALS.map((p) => (
              <li key={p.id}>
                <a href={`?proposal=${p.id}&screen=${screen}`} aria-current={p.id === proposal ? 'page' : undefined}>
                  {p.name}
                </a>
              </li>
            ))}
          </ul>
          <ul>
            {SCREENS.map((s) => (
              <li key={s.id}>
                <a href={`?proposal=${proposal}&screen=${s.id}`} aria-current={s.id === screen ? 'page' : undefined}>
                  {s.name}
                </a>
              </li>
            ))}
          </ul>
          <p>{PROPOSALS.find((p) => p.id === proposal)?.summary}</p>
        </nav>
      )}
      <SiteFrame>
        <Screen id={screen} />
      </SiteFrame>
    </div>
  )
}

function Screen({ id }: { id: ScreenId }) {
  switch (id) {
    case 'top':
      return <TopScreen />
    case 'detail':
      return <DetailScreen />
    case 'result':
      return <ResultScreen />
  }
}
