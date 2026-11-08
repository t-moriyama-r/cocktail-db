import { useId, type ReactNode } from 'react'
import styles from './CocktailForm.module.css'

type ControlProps = { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }

/**
 * ラベルと、検証のメッセージ(あれば)を付けた欄。メッセージは欄の説明(aria-describedby)にもする
 * (銘柄のフォーム(BrandForm)の欄と同じ形)
 */
export function Field({
  label,
  errors,
  narrow = false,
  children,
}: {
  label: string
  errors: readonly string[] | undefined
  narrow?: boolean
  children: (props: ControlProps) => ReactNode
}) {
  const id = useId()
  const errorId = useId()
  const hasErrors = errors !== undefined && errors.length > 0
  return (
    <div className={narrow ? `${styles.field} ${styles.narrow}` : styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      {children({ id, 'aria-invalid': hasErrors, 'aria-describedby': hasErrors ? errorId : undefined })}
      <FieldErrors id={errorId} messages={errors} />
    </div>
  )
}

export function TextField({
  label,
  errors,
  value,
  onChange,
  onBlur,
  inputMode,
  narrow,
}: {
  label: string
  errors: readonly string[] | undefined
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  inputMode?: 'numeric'
  narrow?: boolean
}) {
  return (
    <Field label={label} errors={errors} narrow={narrow}>
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

export type SelectOption = { value: string; label: string }

/**
 * 選択の欄。empty が 'none' なら、旧(append_null_select)と同じく文字の無い空の選択肢を先頭に置く。
 * 'placeholder' なら、まだ選んでいないときだけ出す、選べない「(選んでください)」を置く(空の選択肢の無い製法。新規ではまだ選んでいない)
 */
export function SelectField({
  label,
  errors,
  value,
  options,
  empty,
  onChange,
}: {
  label: string
  errors: readonly string[] | undefined
  value: string
  options: readonly SelectOption[]
  empty: 'none' | 'placeholder'
  onChange: (value: string) => void
}) {
  return (
    <Field label={label} errors={errors}>
      {(props) => (
        <select {...props} className={styles.input} value={value} onChange={(event) => onChange(event.target.value)}>
          {empty === 'none' ? (
            <option value="" />
          ) : (
            value === '' && (
              <option value="" disabled>
                (選んでください)
              </option>
            )
          )}
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  )
}

export function FieldErrors({ id, messages }: { id?: string; messages: readonly string[] | undefined }) {
  if (messages === undefined || messages.length === 0) {
    return null
  }
  return (
    <ul id={id} className={styles.errors}>
      {messages.map((message, index) => (
        // 同じ文が 2 度来ることがある(材料の行ごとの同じメッセージなど)ので、並びの位置で区別する
        <li key={index}>{message}</li>
      ))}
    </ul>
  )
}
