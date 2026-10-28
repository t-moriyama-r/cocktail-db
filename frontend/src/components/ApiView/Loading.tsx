import styles from './ApiView.module.css'

/** API の応答を待っているあいだの表示。旧の詳細は読み終えるまで何も出さなかったが、新は出す(#98) */
export default function Loading() {
  return (
    <p className={styles.loading} role="status">
      読み込み中…
    </p>
  )
}
