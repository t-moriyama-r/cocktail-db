import { useId, type ReactNode } from 'react'
import styles from './AdminField.module.css'

/** Field が欄(children)に渡す属性。欄の要素にそのまま広げる */
export type FieldControlProps = { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }

/**
 * 管理画面のフォームの、ラベルと検証のメッセージ(あれば)を付けた欄(銘柄・ノードの編集フォームで共有する。#704)。
 * メッセージは欄の説明(aria-describedby)にもする。
 * warning(名前・英名の重なりなど)は、保存を止めない知らせなので欄を aria-invalid にはせず、欄の下の文と説明にだけ出す
 */
export function Field({
  label,
  errors,
  warning = null,
  narrow = false,
  children,
}: {
  label: string
  errors: readonly string[] | undefined
  warning?: string | null
  narrow?: boolean
  children: (props: FieldControlProps) => ReactNode
}) {
  const id = useId()
  const errorId = useId()
  const warningId = useId()
  const hasErrors = errors !== undefined && errors.length > 0
  const describedBy = [hasErrors ? errorId : null, warning !== null ? warningId : null].filter((part) => part !== null).join(' ')
  return (
    <div className={narrow ? `${styles.field} ${styles.narrow}` : styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      {children({ id, 'aria-invalid': hasErrors, 'aria-describedby': describedBy === '' ? undefined : describedBy })}
      <FieldErrors id={errorId} messages={errors} />
      <FieldErrors id={warningId} messages={warning === null ? undefined : [warning]} />
    </div>
  )
}

/** 1 行の文字の欄 */
export function TextField({
  label,
  errors,
  warning,
  value,
  onChange,
  onBlur,
  inputMode,
  narrow,
}: {
  label: string
  errors: readonly string[] | undefined
  warning?: string | null
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  inputMode?: 'decimal' | 'numeric'
  narrow?: boolean
}) {
  return (
    <Field label={label} errors={errors} warning={warning} narrow={narrow}>
      {(props) => (
        <input
          {...props}
          className={styles.input}
          type="text"
          inputMode={inputMode}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          onBlur={onBlur}
        />
      )}
    </Field>
  )
}

/** 複数行の文字の欄(説明など) */
export function TextAreaField({
  label,
  errors,
  value,
  onChange,
  rows,
}: {
  label: string
  errors: readonly string[] | undefined
  value: string
  onChange: (value: string) => void
  rows: number
}) {
  return (
    <Field label={label} errors={errors}>
      {(props) => (
        <textarea
          {...props}
          className={styles.textarea}
          rows={rows}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Field>
  )
}

/** 欄の下に並べるメッセージ(検証の誤り・重なりの知らせ)。無ければ何も出さない */
export function FieldErrors({ id, messages }: { id?: string; messages: readonly string[] | undefined }) {
  if (messages === undefined || messages.length === 0) {
    return null
  }
  return (
    <ul id={id} className={styles.errors}>
      {messages.map((message, index) => (
        // 同じ文が 2 度来ることがある(メジャー・お気に入りの両方が必須で落ちたときなど)ので、並びの位置で区別する
        <li key={index}>{message}</li>
      ))}
    </ul>
  )
}
