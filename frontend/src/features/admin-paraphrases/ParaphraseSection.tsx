import { useId, useState, type FormEvent } from 'react'
import type { components } from '../../api/schema'
import AdminList, { type AdminListQuery } from '../../components/AdminList/AdminList'
import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import {
  deleteParaphrase,
  failureMessage,
  loadParaphrases,
  saveParaphrase,
  type FieldErrors,
  type SaveParaphraseBody,
} from './adminParaphraseApi'
import FormMessage, { type Message } from './FormMessage'
import ParaphraseTargetPicker from './ParaphraseTargetPicker'
import { pickedTargetOf, resolvedLabel, type PickedTarget } from './paraphraseTarget'
import TextField from './TextField'
import styles from './AdminParaphrases.module.css'

type ParaphraseResource = components['schemas']['ParaphraseResource']

/** フォームの入力。欄はどれも文字列で持ち、送るときに空を null にする */
type FormValues = { name: string; command: '' | 'hot'; note: string; converted: string; middle: string }

const EMPTY_VALUES: FormValues = { name: '', command: '', note: '', converted: '', middle: '' }

/** command の選択肢(旧 ParaPhraseList.vue と同じ) */
const COMMAND_OPTIONS: readonly { value: FormValues['command']; label: string }[] = [
  { value: '', label: '(未選択)' },
  { value: 'hot', label: '強制ホット' },
]

/**
 * 言い換え辞書の一覧とフォーム(#67。旧 Admin/Super/ParaPhraseList.vue)。
 *
 * - 一覧(#120 の部品)の 1 行を押すと、その語をフォームに読み込む(旧と同じ)。行には変換前・変換後(とミドル)と、解決した先のパンくずを出す
 * - フォームは、変換前・command・note・変換後・ミドル。変換後は木から選ぶ(ParaphraseTargetPicker)か、手で名前を入れる
 * - 旧に無い保存・削除を足す。保存に失敗したら欄ごとに誤りを出し、入力を残す。削除は確かめてから消す
 *   (docs/decisions/admin-screens.md の既定)
 */
export default function ParaphraseSection() {
  // 編集している語(ID と、読み込んだときの変換前)。新しく足すときは null
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [values, setValues] = useState<FormValues>(EMPTY_VALUES)
  const [picked, setPicked] = useState<PickedTarget | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<Message>(null)
  const [pending, setPending] = useState(false)
  // 一覧の API を呼ぶ関数。保存・削除の後は、同じ中身の別の関数に替えて一覧を読み直させる(AdminList は load が変わると呼び直す)
  const [load, setLoad] = useState(() => loadParaphrases)
  const reloadList = () => setLoad(() => (query: AdminListQuery) => loadParaphrases(query))
  const commandId = useId()

  const set = (patch: Partial<FormValues>) => setValues((current) => ({ ...current, ...patch }))
  const resetForm = () => {
    setEditing(null)
    setValues(EMPTY_VALUES)
    setPicked(null)
    setErrors({})
  }

  const edit = (paraphrase: ParaphraseResource) => {
    setEditing({ id: paraphrase.id, name: paraphrase.name })
    setValues({
      name: paraphrase.name,
      command: paraphrase.command === 'hot' ? 'hot' : '',
      note: paraphrase.note ?? '',
      converted: paraphrase.converted,
      middle: paraphrase.middle ?? '',
    })
    setPicked(pickedTargetOf(paraphrase))
    setErrors({})
    setMessage(null)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setPending(true)
    const result = await saveParaphrase(editing?.id ?? null, toBody(values))
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
    if (editing === null || !window.confirm(`言い換え「${editing.name}」を消します。よろしいですか?`)) {
      return
    }
    setPending(true)
    const result = await deleteParaphrase(editing.id)
    setPending(false)
    if (result.kind === 'deleted') {
      resetForm()
      setMessage({ kind: 'notice', text: `「${editing.name}」を消しました` })
      reloadList()
    } else {
      setMessage({ kind: 'error', text: failureMessage(result, '削除') })
    }
  }

  return (
    <section className={styles.section} aria-labelledby="admin-paraphrases-title">
      <h2 className={styles.sectionTitle} id="admin-paraphrases-title">
        言い換え辞書
      </h2>
      <form className={styles.form} aria-label="言い換えのフォーム" onSubmit={(event) => void submit(event)}>
        <p className={styles.formTitle}>
          {editing === null ? '新しい言い換えを足す' : `言い換え「${editing.name}」を編集中`}
        </p>
        <div className={styles.row}>
          <TextField label="変換前" value={values.name} onChange={(name) => set({ name })} errors={errors.name} />
          <div className={styles.field}>
            <label className={styles.label} htmlFor={commandId}>
              command
            </label>
            <select
              id={commandId}
              className={styles.input}
              value={values.command}
              onChange={(event) => set({ command: event.target.value === 'hot' ? 'hot' : '' })}
            >
              {COMMAND_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
            {errors.command !== undefined && <p className={styles.fieldError}>{errors.command.join(' ')}</p>}
          </div>
        </div>
        <TextField label="note" value={values.note} onChange={(note) => set({ note })} errors={errors.note} multiline />
        <div className={styles.row}>
          <TextField
            label="変換後"
            value={values.converted}
            onChange={(converted) => {
              set({ converted })
              setPicked(null)
            }}
            errors={errors.converted}
            hint="ノードか銘柄の名前。下の木から選ぶと入ります"
          />
          <TextField
            label="ミドル"
            value={values.middle}
            onChange={(middle) => {
              set({ middle })
              setPicked(null)
            }}
            errors={errors.middle}
            hint="変換後のノードの配下のノードの名前(無ければ空)"
          />
        </div>
        <ParaphraseTargetPicker
          picked={picked}
          onPick={(next, names) => {
            setPicked(next)
            set(names)
          }}
        />
        <div className={styles.actions}>
          <button className={styles.primaryButton} type="submit" disabled={pending}>
            保存する
          </button>
          {editing !== null && (
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
      <section aria-label="言い換えの一覧">
        <AdminList
          load={load}
          itemKey={(paraphrase) => paraphrase.id}
          renderItem={(paraphrase) => <ParaphraseItem paraphrase={paraphrase} onEdit={edit} />}
        />
      </section>
    </section>
  )
}

/**
 * 一覧の 1 行。旧(ListComponent.vue)と同じく「〈変換前〉 → 〈変換後〉 【〈ミドル〉】」と、note があればその印を出し、
 * 変換前を押すとフォームに読み込む。旧に無い、解決した先のパンくずを後ろに足す(docs/decisions/admin-paraphrases.md)
 */
function ParaphraseItem({
  paraphrase,
  onEdit,
}: {
  paraphrase: ParaphraseResource
  onEdit: (p: ParaphraseResource) => void
}) {
  const label = resolvedLabel(paraphrase)
  return (
    <div className={styles.item}>
      <span>
        <button className={styles.itemButton} type="button" onClick={() => onEdit(paraphrase)}>
          {paraphrase.name}
        </button>
        {` → ${paraphrase.converted}`}
        {paraphrase.middle !== null && ` 【${paraphrase.middle}】`}
        {paraphrase.note !== null && (
          <>
            {' '}
            <FontAwesomeIcon icon="lightbulb" label="note あり" />
          </>
        )}
      </span>
      <span className={label === null ? styles.unresolved : styles.resolved}>
        {label ?? '解決できません(この名前のノード・銘柄がありません)'}
      </span>
    </div>
  )
}

/** フォームの入力を API の本文にする。空の欄は null(指定なし)で送る */
function toBody(values: FormValues): SaveParaphraseBody {
  const orNull = (value: string) => (value.trim() === '' ? null : value)
  return {
    name: values.name,
    converted: values.converted,
    middle: orNull(values.middle),
    command: values.command === 'hot' ? 'hot' : null,
    note: orNull(values.note),
  }
}
