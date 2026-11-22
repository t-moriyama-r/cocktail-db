import styles from './AdminParaphrases.module.css'

/** 知らせ(保存・削除できた)か、失敗の文。出さないときは null */
export type Message = { kind: 'notice' | 'error'; text: string } | null

/** 保存・削除の結果の文。知らせは status、失敗は alert で読み上げる */
export default function FormMessage({ message }: { message: Message }) {
  if (message === null) {
    return null
  }
  return message.kind === 'notice' ? (
    <p className={styles.notice} role="status">
      {message.text}
    </p>
  ) : (
    <p className={styles.error} role="alert">
      {message.text}
    </p>
  )
}
