import { useState } from 'react'
import type { components } from '../../api/schema'
import { deleteCategory, type DeleteCategoryResult } from './categoryApi'
import styles from './CategoryActions.module.css'
import { useMounted } from './useMounted'

type CategoryResource = components['schemas']['CategoryResource']

type Props = {
  /** 消すノード(編集で開いたノード) */
  node: CategoryResource
  /** 消せたときに呼ぶ(画面はカテゴリ木へ戻る) */
  onDeleted: (name: string) => void
}

type DeleteState = { kind: 'idle' } | { kind: 'deleting' } | Exclude<DeleteCategoryResult, { kind: 'deleted' }>

/**
 * ノードの削除(#125。旧に無い)。消す前に確かめる。
 *
 * 消せないノード(子・銘柄・材料・ベース・アフィリエイトのどれかがある。#121)は、開いたときの応答(delete_blockers)の
 * 理由をそのまま出し、消すボタンを押せなくする。開いた後にほかで増えて 409 になったときも、応答の理由をそのまま出す。
 */
export default function CategoryDelete({ node, onDeleted }: Props) {
  const [deletion, setDeletion] = useState<DeleteState>({ kind: 'idle' })
  const mounted = useMounted()
  const blockers =
    deletion.kind === 'blocked' ? deletion.reasons : node.delete_blockers.map((blocker) => blocker.message)

  const submit = async () => {
    if (!window.confirm(`「${node.name}」を消します。よろしいですか?`)) return
    setDeletion({ kind: 'deleting' })
    const result = await deleteCategory(node.id)
    // 待つ間に別の画面へ移っていたら、カテゴリ木へ戻さない
    if (!mounted.current) return
    if (result.kind === 'deleted') {
      onDeleted(node.name)
      return
    }
    setDeletion(result)
  }

  return (
    <section className={styles.section} aria-label="ノードの削除">
      <h2 className={styles.title}>ノードの削除</h2>
      {blockers.length > 0 ? (
        <div className={styles.failure} role={deletion.kind === 'blocked' ? 'alert' : undefined}>
          <p className={styles.text}>このノードは消せません</p>
          <ul className={styles.reasons}>
            {blockers.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        </div>
      ) : (
        <p className={styles.text}>消すと元に戻せません。</p>
      )}
      <div>
        <button
          className={`${styles.button} ${styles.danger}`}
          type="button"
          disabled={blockers.length > 0 || deletion.kind === 'deleting'}
          onClick={() => void submit()}
        >
          このノードを消す
        </button>
      </div>
      {(deletion.kind === 'http-error' || deletion.kind === 'network-error') && (
        <p className={styles.failure} role="alert">
          {deletion.kind === 'http-error'
            ? `消せませんでした(エラー ${deletion.status})。時間をおいて、もう一度お試しください。`
            : '消せませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'}
        </p>
      )}
    </section>
  )
}
