import { useId, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import type { components } from '../../api/schema'
import { ADMIN_DICTIONARIES_PATH } from '../../components/AdminLayout/adminPaths'
import { storageImageUrl } from '../../utils/storageImageUrl'
import { termLabel } from '../dictionary/termLabel'
import {
  deleteDictionary,
  saveDictionary,
  type DeleteResult,
  type FieldErrors,
  type RequestFailure,
} from './adminDictionaryApi'
import { EMPTY_DICTIONARY_FORM, toDictionaryForm, type DictionaryFormValues } from './dictionaryFormValues'
import type { AdminDictionariesNotice } from './adminDictionariesNotice'
import styles from './DictionaryForm.module.css'

type DictionaryResource = components['schemas']['DictionaryResource']
type DictionaryCategory = components['schemas']['DictionaryListCategoryResource']

type Props = {
  /** 分類の選択肢(一覧の API の分類。用語の無い分類も選べる) */
  categories: readonly DictionaryCategory[]
  /** 編集で開いた用語。新規なら無し */
  dictionary?: DictionaryResource
}

/** 欄の名前(API の項目の名前)と、画面に出す名前。誤りの一覧で、どの欄の誤りかを出すのにも使う */
const FIELD_LABELS: Record<string, string> = {
  dictionary_category_id: '分類',
  name: '名前',
  name_eng: '英名',
  description: '説明',
  youtube: 'YouTube',
  youtube_start_hour: '開始位置(時)',
  youtube_start_minute: '開始位置(分)',
  youtube_start_second: '開始位置(秒)',
  image: '画像',
  remove_image: '画像を消す',
  image_txt: 'リンクテキスト',
  image_url: 'リンク URL',
}

type SubmitState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'invalid'; errors: FieldErrors }
  | RequestFailure

type DeleteState = { kind: 'idle' } | { kind: 'pending' } | Exclude<DeleteResult, { kind: 'deleted' }>

/**
 * 用語の新規・編集の欄と、保存・削除のボタン(#66)。旧の編集画面はカテゴリの編集画面を写したまま途中でやめたもので
 * (旧 Detail/DictionaryEdit.vue)、写す項目は無い。欄は用語の列(docs/schema.md の dictionaries)に合わせた。
 *
 * - 保存に失敗したら、欄ごとに誤りを出し、画面を移らない。入力は残す(#284 の既定)
 * - 保存できたら一覧へ移り、保存したことを一覧に出す
 * - 削除は確かめてから消す。消せない(グラスに使われている など)ときは、API が返す理由をそのまま出して残す(#315)
 */
export default function DictionaryForm({ categories, dictionary }: Props) {
  const navigate = useNavigate()
  const formId = useId()
  const [values, setValues] = useState<DictionaryFormValues>(() =>
    dictionary === undefined ? EMPTY_DICTIONARY_FORM : toDictionaryForm(dictionary),
  )
  const [submit, setSubmit] = useState<SubmitState>({ kind: 'idle' })
  const [deletion, setDeletion] = useState<DeleteState>({ kind: 'idle' })
  const errors = submit.kind === 'invalid' ? submit.errors : {}
  const busy = submit.kind === 'pending' || deletion.kind === 'pending'

  const set = <K extends keyof DictionaryFormValues>(key: K, value: DictionaryFormValues[K]) =>
    setValues((current) => ({ ...current, [key]: value }))

  const done = (notice: AdminDictionariesNotice) => void navigate(ADMIN_DICTIONARIES_PATH, { state: notice })

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSubmit({ kind: 'pending' })
    setDeletion({ kind: 'idle' })
    const result = await saveDictionary(dictionary?.id ?? null, values)
    if (result.kind === 'saved') {
      done({ notice: `「${termLabel(result.data)}」を保存しました。` })
      return
    }
    setSubmit(result)
  }

  const handleDelete = async () => {
    if (dictionary === undefined || !window.confirm(`「${termLabel(dictionary)}」を消します。よろしいですか?`)) {
      return
    }
    setDeletion({ kind: 'pending' })
    setSubmit({ kind: 'idle' })
    const result = await deleteDictionary(dictionary.id)
    if (result.kind === 'deleted') {
      done({ notice: `「${termLabel(dictionary)}」を消しました。` })
      return
    }
    setDeletion(result)
  }

  /** 欄の id と、誤りの文の id(aria-describedby で欄に結び付ける) */
  const fieldId = (name: string) => `${formId}-${name}`
  const errorId = (name: string) => `${formId}-${name}-error`
  const fieldProps = (name: string) => ({
    id: fieldId(name),
    'aria-invalid': errors[name] !== undefined ? true : undefined,
    'aria-describedby': errors[name] !== undefined ? errorId(name) : undefined,
  })
  const field = (name: string, control: ReactNode) => (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={fieldId(name)}>
        {FIELD_LABELS[name]}
      </label>
      {control}
      <FieldError id={errorId(name)} messages={errors[name]} />
    </div>
  )

  // 欄の無い項目の誤り(届いたときに見落とさないよう、上の誤りの案内に出す)
  const otherErrors = Object.entries(errors).filter(
    ([name]) => !(name in FIELD_LABELS) || name === 'remove_image',
  )

  return (
    <form className={styles.form} onSubmit={(event) => void handleSubmit(event)} noValidate>
      {submit.kind !== 'idle' && submit.kind !== 'pending' && (
        <div className={styles.alert} role="alert">
          <p>{submitErrorMessage(submit)}</p>
          {otherErrors.length > 0 && (
            <ul>
              {otherErrors.flatMap(([name, messages]) =>
                messages.map((message) => <li key={`${name}-${message}`}>{message}</li>),
              )}
            </ul>
          )}
        </div>
      )}

      {field(
        'dictionary_category_id',
        <select
          className={styles.input}
          {...fieldProps('dictionary_category_id')}
          value={values.dictionary_category_id}
          onChange={(event) => set('dictionary_category_id', event.target.value)}
        >
          <option value="">選んでください</option>
          {categories.map((category) => (
            <option key={category.id} value={String(category.id)}>
              {termLabel(category)}
            </option>
          ))}
        </select>,
      )}
      {field(
        'name',
        <input
          className={styles.input}
          {...fieldProps('name')}
          type="text"
          value={values.name}
          onChange={(event) => set('name', event.target.value)}
        />,
      )}
      {field(
        'name_eng',
        <input
          className={styles.input}
          {...fieldProps('name_eng')}
          type="text"
          value={values.name_eng}
          onChange={(event) => set('name_eng', event.target.value)}
        />,
      )}
      {field(
        'description',
        <textarea
          className={`${styles.input} ${styles.textarea}`}
          {...fieldProps('description')}
          rows={8}
          value={values.description}
          onChange={(event) => set('description', event.target.value)}
        />,
      )}
      {field(
        'youtube',
        <input
          className={styles.input}
          {...fieldProps('youtube')}
          type="text"
          placeholder="URL か動画 ID"
          value={values.youtube}
          onChange={(event) => set('youtube', event.target.value)}
        />,
      )}
      <fieldset className={styles.start}>
        <legend className={styles.label}>開始位置</legend>
        {(['youtube_start_hour', 'youtube_start_minute', 'youtube_start_second'] as const).map((name) => (
          <div key={name} className={styles.startField}>
            <input
              className={`${styles.input} ${styles.number}`}
              {...fieldProps(name)}
              aria-label={FIELD_LABELS[name]}
              type="number"
              inputMode="numeric"
              min={0}
              value={values[name]}
              onChange={(event) => set(name, event.target.value)}
            />
            <span aria-hidden="true">{START_UNITS[name]}</span>
            <FieldError id={errorId(name)} messages={errors[name]} />
          </div>
        ))}
      </fieldset>
      <div className={styles.field}>
        <label className={styles.label} htmlFor={fieldId('image')}>
          {FIELD_LABELS.image}
        </label>
        {dictionary?.image != null && (
          <div className={styles.currentImage}>
            <img className={styles.preview} src={storageImageUrl(dictionary.image)} alt="今の画像" />
            <label className={styles.check}>
              <input
                type="checkbox"
                checked={values.remove_image}
                onChange={(event) => set('remove_image', event.target.checked)}
              />
              画像を消す
            </label>
          </div>
        )}
        <input
          {...fieldProps('image')}
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          onChange={(event) => set('image', event.target.files?.[0] ?? null)}
        />
        <FieldError id={errorId('image')} messages={errors.image} />
      </div>
      {field(
        'image_txt',
        <input
          className={styles.input}
          {...fieldProps('image_txt')}
          type="text"
          value={values.image_txt}
          onChange={(event) => set('image_txt', event.target.value)}
        />,
      )}
      {field(
        'image_url',
        <input
          className={styles.input}
          {...fieldProps('image_url')}
          type="url"
          value={values.image_url}
          onChange={(event) => set('image_url', event.target.value)}
        />,
      )}

      <div className={styles.actions}>
        <button className={styles.save} type="submit" disabled={busy}>
          {submit.kind === 'pending' ? '保存しています…' : '保存する'}
        </button>
        {dictionary !== undefined && (
          <button className={styles.delete} type="button" disabled={busy} onClick={() => void handleDelete()}>
            {deletion.kind === 'pending' ? '削除しています…' : '削除する'}
          </button>
        )}
      </div>
      {deletion.kind !== 'idle' && deletion.kind !== 'pending' && (
        <div className={styles.alert} role="alert">
          {deletion.kind === 'blocked' ? (
            <>
              <p>この用語は消せません。</p>
              <ul>
                {deletion.reasons.map((reason) => (
                  <li key={reason}>{reason}</li>
                ))}
              </ul>
            </>
          ) : (
            <p>{failureMessage('削除できませんでした', deletion)}</p>
          )}
        </div>
      )}
    </form>
  )
}

const START_UNITS = { youtube_start_hour: '時', youtube_start_minute: '分', youtube_start_second: '秒' } as const

function FieldError({ id, messages }: { id: string; messages: string[] | undefined }) {
  if (messages === undefined) {
    return null
  }
  return (
    <p className={styles.fieldError} id={id}>
      {messages.join(' ')}
    </p>
  )
}

function submitErrorMessage(state: Exclude<SubmitState, { kind: 'idle' | 'pending' }>): string {
  if (state.kind === 'invalid') {
    return '保存できませんでした。入力を確かめてください。'
  }
  return failureMessage('保存できませんでした', state)
}

function failureMessage(what: string, failure: RequestFailure): string {
  switch (failure.kind) {
    case 'network-error':
      return `${what}。通信に失敗しました。接続を確かめて、もう一度お試しください。`
    case 'http-error':
      return `${what}(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
  }
}
