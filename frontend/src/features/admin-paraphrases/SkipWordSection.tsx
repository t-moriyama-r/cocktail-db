import { useState, type FormEvent } from 'react'
import type { components } from '../../api/schema'
import AdminList from '../../components/AdminList/AdminList'
import { deleteSkipWord, failureMessage, loadSkipWords, saveSkipWord, type FieldErrors } from './adminParaphraseApi'
import FormMessage, { type Message } from './FormMessage'
import TextField from './TextField'
import styles from './AdminParaphrases.module.css'

type SkipWordResource = components['schemas']['SkipWordResource']

/**
 * スキップ語の一覧とフォーム(#67)。旧はスキップ語の画面が無く、自動取り込みの画面から足すだけだった(旧 AutoInputController@addSkipDB)。
 *
 * 言い換え辞書と同じ形にする: 一覧(#120 の部品。API が全部を返すので絞り込みとページ送りは出さない)の 1 行を押すとフォームに読み込み、
 * 保存・削除する。保存に失敗したら欄に誤りを出して入力を残し、削除は確かめてから消す
 */
export default function SkipWordSection() {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [editingName, setEditingName] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<Message>(null)
  const [pending, setPending] = useState(false)
  // 一覧の API を呼ぶ関数。保存・削除の後は、同じ中身の別の関数に替えて一覧を読み直させる(AdminList は load が変わると呼び直す)
  const [load, setLoad] = useState(() => loadSkipWords)
  const reloadList = () => setLoad(() => () => loadSkipWords())

  const resetForm = () => {
    setEditingId(null)
    setName('')
    setEditingName('')
    setErrors({})
  }

  const edit = (skipWord: SkipWordResource) => {
    setEditingId(skipWord.id)
    setName(skipWord.name)
    setEditingName(skipWord.name)
    setErrors({})
    setMessage(null)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setPending(true)
    const result = await saveSkipWord(editingId, { name })
    setPending(false)
    if (result.kind === 'saved') {
      resetForm()
      setMessage({ kind: 'notice', text: `「${result.data.name}」を保存しました` })
      reloadList()
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
      setMessage({ kind: 'error', text: '入力に誤りがあります。欄の下の説明を見て直してください。' })
    } else {
      setMessage({ kind: 'error', text: failureMessage(result, '保存') })
    }
  }

  const remove = async () => {
    if (editingId === null || !window.confirm(`スキップ語「${editingName}」を消します。よろしいですか?`)) {
      return
    }
    setPending(true)
    const result = await deleteSkipWord(editingId)
    setPending(false)
    if (result.kind === 'deleted') {
      resetForm()
      setMessage({ kind: 'notice', text: `「${editingName}」を消しました` })
      reloadList()
    } else {
      setMessage({ kind: 'error', text: failureMessage(result, '削除') })
    }
  }

  return (
    <section className={styles.section} aria-labelledby="admin-skip-words-title">
      <h2 className={styles.sectionTitle} id="admin-skip-words-title">
        スキップ語
      </h2>
      <form className={styles.form} aria-label="スキップ語のフォーム" onSubmit={(event) => void submit(event)}>
        <p className={styles.formTitle}>
          {editingId === null ? '新しいスキップ語を足す' : `スキップ語「${editingName}」を編集中`}
        </p>
        <TextField
          label="スキップ語"
          value={name}
          onChange={(value) => {
            setName(value)
            setErrors({})
          }}
          errors={errors.name}
        />
        <div className={styles.actions}>
          <button className={styles.primaryButton} type="submit" disabled={pending}>
            保存する
          </button>
          {editingId !== null && (
            <>
              <button className={styles.button} type="button" disabled={pending} onClick={() => void remove()}>
                削除する
              </button>
              <button
                className={styles.button}
                type="button"
                disabled={pending}
                onClick={() => {
                  resetForm()
                  setMessage(null)
                }}
              >
                新しく足す
              </button>
            </>
          )}
        </div>
        <FormMessage message={message} />
      </form>
      <section aria-label="スキップ語の一覧">
        <AdminList
          load={load}
          searchable={false}
          itemKey={(skipWord) => skipWord.id}
          renderItem={(skipWord) => (
            <button className={styles.itemButton} type="button" disabled={pending} onClick={() => edit(skipWord)}>
              {skipWord.name}
            </button>
          )}
        />
      </section>
    </section>
  )
}
