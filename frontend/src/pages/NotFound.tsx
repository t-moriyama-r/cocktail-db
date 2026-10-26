import { Link } from 'react-router'

/**
 * 存在しない URL で出す画面。旧サイトは存在しない URL でトップを出していたが、
 * 開いた URL が間違っていると分かるように、404 の画面を出してトップへのリンクを置く。
 */
export default function NotFound() {
  return (
    <main>
      <h1>ページが見つかりません</h1>
      <p>お探しのページは存在しないか、移動した可能性があります。</p>
      <p>
        <Link to="/">トップへ戻る</Link>
      </p>
    </main>
  )
}
