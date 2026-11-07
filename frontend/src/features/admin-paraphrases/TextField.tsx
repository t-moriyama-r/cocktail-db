import { useId } from 'react'
import styles from './AdminParaphrases.module.css'

type Props = {
  label: string
  value: string
  onChange: (value: string) => void
  /** 422 で返った、この欄の誤り。無ければ空 */
  errors?: readonly string[]
  /** 欄の下に添える説明 */
  hint?: string
  multiline?: boolean
}

/**
 * フォームの 1 欄(名前・入力・説明・誤り)。誤りがあれば aria-invalid にし、誤りの文を欄の説明に結び付ける
 * (読み上げで、どの欄の誤りかが分かるようにする)
 */
export default function TextField({ label, value, onChange, errors = [], hint, multiline = false }: Props) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint === undefined ? null : hintId, errors.length === 0 ? null : errorId].filter(
    (x) => x !== null,
  )
  const inputProps = {
    id,
    className: styles.input,
    value,
    'aria-invalid': errors.length > 0,
    'aria-describedby': describedBy.length === 0 ? undefined : describedBy.join(' '),
  }

  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      {multiline ? (
        <textarea {...inputProps} rows={2} onChange={(event) => onChange(event.target.value)} />
      ) : (
        <input {...inputProps} type="text" onChange={(event) => onChange(event.target.value)} />
      )}
      {hint !== undefined && (
        <p className={styles.hint} id={hintId}>
          {hint}
        </p>
      )}
      {errors.length > 0 && (
        <p className={styles.fieldError} id={errorId}>
          {errors.join(' ')}
        </p>
      )}
    </div>
  )
}
