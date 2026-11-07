import { useId, useState, type FormEvent } from 'react'
import { api } from '../../api/client'
import { secretStatusLabel, sourceLabel, updatedLabel, type SettingItem, type SettingKey } from './settingView'
import styles from './AdminSettings.module.css'

type SaveState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'saved' }
  /** 秘密の項目を空のまま保存した。値を消さないよう、API を呼ばない */
  | { kind: 'unchanged' }
  /** 規則に合わない値(422)。サーバーのメッセージを項目の下に出す */
  | { kind: 'invalid'; messages: string[] }
  | { kind: 'http-error'; status: number }
  | { kind: 'network-error' }

type Props = {
  /** GET /api/admin/settings が返した 1 項目 */
  initial: SettingItem
}

/**
 * 設定の 1 項目を書き換えるフォーム(#133)。API(PUT /api/admin/settings)が 1 項目ずつ書き換えるので、項目ごとに保存する。
 *
 * - 秘密の項目は、値を画面に出さず、「設定済み(末尾 4 文字)」/「未設定」だけを出す。値の欄はいつも空で、
 *   入れたときだけ上書きする。空のまま保存しても API を呼ばず、値を消さない(#133 の追記)。
 *   入れた値を DOM の属性に残さないよう、欄は React の state で持たない(送るときにフォームから読み、保存できたら空に戻す)
 * - 秘密でない項目は、いまの値を欄に入れて出す。空にして保存すると、API は保存した値を消して .env の値に戻す
 *   (docs/decisions/settings.md の 2)
 */
export default function SettingForm({ initial }: Props) {
  const [item, setItem] = useState(initial)
  const [value, setValue] = useState(initial.value ?? '')
  const [save, setSave] = useState<SaveState>({ kind: 'idle' })
  const id = useId()
  const inputId = `${id}-input`
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const titleId = `${id}-title`

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = event.currentTarget
    const entered = item.secret ? String(new FormData(form).get('value') ?? '') : value
    if (item.secret && entered === '') {
      setSave({ kind: 'unchanged' })
      return
    }

    setSave({ kind: 'pending' })
    let result
    try {
      result = await api.PUT('/admin/settings', {
        // キーは API が返した定義のキー。書き換えの API の enum と同じ集合(#132 の TC-132-13)
        body: { key: item.key as SettingKey, value: entered === '' ? null : entered },
      })
    } catch {
      setSave({ kind: 'network-error' })
      return
    }

    const { data, error, response } = result
    if (data !== undefined) {
      setItem(data.data)
      setValue(data.data.value ?? '')
      if (item.secret) form.reset()
      setSave({ kind: 'saved' })
      return
    }
    if (response.status === 422 && error !== undefined) {
      setSave({ kind: 'invalid', messages: Object.values(error.errors).flat() })
      return
    }
    setSave({ kind: 'http-error', status: response.status })
  }

  const invalid = save.kind === 'invalid'

  return (
    <section className={styles.item} aria-labelledby={titleId}>
      <h2 className={styles.itemTitle} id={titleId}>
        {item.label}
      </h2>
      <p className={styles.description}>{item.description}</p>
      <dl className={styles.meta}>
        {item.secret && (
          <>
            <dt>いまの値</dt>
            <dd>{secretStatusLabel(item)}</dd>
          </>
        )}
        <dt>出どころ</dt>
        <dd>{sourceLabel(item.source)}</dd>
        <dt>最後に変えた人と日時</dt>
        <dd>{updatedLabel(item)}</dd>
      </dl>
      <form className={styles.form} aria-labelledby={titleId} onSubmit={(event) => void handleSubmit(event)}>
        <label className={styles.label} htmlFor={inputId}>
          {item.secret ? '新しい値' : '値'}
        </label>
        <div className={styles.field}>
          {item.secret ? (
            <input
              className={styles.input}
              id={inputId}
              name="value"
              type="password"
              autoComplete="new-password"
              spellCheck={false}
              placeholder={item.is_set ? '上書きするときだけ入れる' : '値を入れる'}
              aria-describedby={invalid ? `${errorId} ${hintId}` : hintId}
              aria-invalid={invalid}
            />
          ) : (
            <input
              className={styles.input}
              id={inputId}
              name="value"
              type="text"
              autoComplete="off"
              spellCheck={false}
              value={value}
              onChange={(event) => setValue(event.target.value)}
              aria-describedby={invalid ? `${errorId} ${hintId}` : hintId}
              aria-invalid={invalid}
            />
          )}
          <button className={styles.save} type="submit" disabled={save.kind === 'pending'}>
            保存
          </button>
        </div>
        {invalid && (
          <p className={styles.error} id={errorId} role="alert">
            この値は保存できません。{save.messages.join(' ')}
          </p>
        )}
        <p className={styles.hint} id={hintId}>
          {item.secret
            ? '入れたときだけ上書きします。空のまま保存しても、いまの値は消えません。'
            : '空にして保存すると、この画面で保存した値を消し、サーバーの .env の値(無ければ未設定)に戻します。'}
        </p>
        <SaveMessage save={save} />
      </form>
    </section>
  )
}

/** 保存した結果のメッセージ。規則に合わない値のメッセージは、欄の下に出す */
function SaveMessage({ save }: { save: SaveState }) {
  switch (save.kind) {
    case 'saved':
      return (
        <p className={styles.saved} role="status">
          保存しました。
        </p>
      )
    case 'unchanged':
      return (
        <p className={styles.saved} role="status">
          値が空なので、変えていません。
        </p>
      )
    case 'http-error':
      // ログインの期限が切れた後の保存は 401。何度送っても同じなので、ログインし直す手順を出す。開き直すと入力が消えるので、先に控えるよう書く(RC-17)
      return (
        <p className={styles.error} role="alert">
          {save.status === 401
            ? 'ログインの期限が切れていて保存できませんでした。入力を控えてから、ページを開き直してログインし直してください。'
            : `保存できませんでした(エラー ${save.status})。時間をおいて、もう一度お試しください。`}
        </p>
      )
    case 'network-error':
      return (
        <p className={styles.error} role="alert">
          保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。
        </p>
      )
    default:
      return null
  }
}
