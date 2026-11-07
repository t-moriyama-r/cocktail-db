import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import type { components } from '../../api/schema'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { storageImageUrl } from '../../utils/storageImageUrl'
import { sanitizeDigits, toHalfWidthAlphanumeric } from '../admin-brands/brandFormValues'
import { saveCategory, type CategoryFieldErrors, type CategorySaveWarning, type SaveCategoryResult } from './categoryApi'
import type { CategoryFormValues, CategoryKeptValues } from './categoryFormValues'
import styles from './CategoryForm.module.css'

type AdminTreeItem = components['schemas']['CategoryTreeItemResource']

/** 親の欄。新規は木から選び、編集は今の親(根から親までの名前)を出すだけにする(編集での親の付け替えは #675 で足す) */
export type ParentField =
  | { kind: 'select'; tree: readonly AdminTreeItem[] }
  | { kind: 'fixed'; path: readonly string[] }

type Props = {
  /** 編集するノードの ID。新規なら null */
  nodeId: number | null
  /** 最初の入力(新規は空、編集は開いたノードの値) */
  initialValues: CategoryFormValues
  /** このフォームでは変えずに送り直す値(根の役割・有名な銘柄) */
  kept: CategoryKeptValues
  /** 今の画像(API の image の値。public ディスクのファイル名か外部の URL)。無ければ null */
  currentImage: string | null
  parent: ParentField
  /** 保存できたときに呼ぶ(画面はカテゴリ木へ戻る) */
  onSaved: (name: string, warnings: readonly CategorySaveWarning[]) => void
}

type SaveState = { kind: 'idle' } | { kind: 'saving' } | Exclude<SaveCategoryResult, { kind: 'saved' }>

/** 開始位置の欄に入れられる桁(旧と同じく、時は 1 桁、分・秒は 2 桁) */
const HOUR_DIGITS = 1
const MINUTE_OR_SECOND_DIGITS = 2

/** 欄の下に出す検証のメッセージの項目。ここに無い項目(有名な銘柄・根の役割など)のメッセージは、送信の下にまとめて出す */
const FIELDS_ON_FORM = new Set([
  'parent_id',
  'name',
  'name_eng',
  'description',
  'is_major',
  'is_like',
  'is_powder',
  'youtube',
  'youtube_start_hour',
  'youtube_start_minute',
  'youtube_start_second',
  'image',
  'remove_image',
  'image_txt',
  'image_url',
])

/**
 * 管理画面のノードの編集フォームの基本の項目(旧 `Detail/CategoryEdit.vue`・`MiddleEdit.vue`・`component/NamesAndDescription.vue`。#308)。
 * 旧のカテゴリとミドルは同じ項目なので、任意の深さのノードを 1 つのフォームで編集する。
 *
 * 並びは旧と同じく、親 / 名前・英名 / メジャー・お気に入り・粉もの / 説明 / YouTube と開始位置 / 画像 / リンクテキスト・リンクURL / 送信
 * (docs/decisions/admin-category-form.md)。
 * - 親は、新規なら木(#123 と同じ管理 API の木)から 1 つ選ぶ。根も選べる。根を作る(親を選ばない)のは #310
 * - 説明は、API が返す編集の形(記法に指す先の名前を添えたもの。#287)のまま編集し、そのまま送る
 * - 開始位置は数字だけを時 1 桁・分秒 2 桁まで受け付ける(旧と同じ)。英名は欄を離れたときに全角の英数字を半角にする(旧と同じ)
 * - メジャー・お気に入り・粉ものは、押すたびに入れ・外しができる(旧は押すと必ず入れるだけで、外せなかった)
 * - 画像は選ぶとその場で出し、差し替えられる。今の画像があれば「画像を消す」で画像だけを消せる(#288)
 * - 検証は API に任せ、422 は項目ごとに欄の下に出し、画面を移らない。入力は残す(docs/decisions/admin-screens.md の既定)
 *
 * 有名な銘柄の選択・その場での作成は #309、根の役割は #310 で足す。それまでは今の値のまま送り直す(CategoryKeptValues)。
 * 名前・英名の重なりを入れたそばに出す(旧の duplicate_check)のは、問い合わせる API(#673)ができてから #674 で足す。
 */
export default function CategoryForm({ nodeId, initialValues, kept, currentImage, parent, onSaved }: Props) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState<CategoryFieldErrors>({})
  const [save, setSave] = useState<SaveState>({ kind: 'idle' })
  const [picked, setPicked] = useState<{ file: File; url: string | null } | null>(null)
  // 「画像を消す」を入れたときに、選んでいたファイルを欄からも外すため、ファイルの欄を作り直す
  const [fileInputKey, setFileInputKey] = useState(0)

  const set = <K extends keyof CategoryFormValues>(key: K, value: CategoryFormValues[K]) =>
    setValues((current) => ({ ...current, [key]: value }))

  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const pickImage = (file: File | null) => {
    setValues((current) => ({ ...current, image: file, removeImage: false }))
    if (file === null) {
      setPicked(null)
      return
    }
    setPicked({ file, url: null })
    // 旧と同じく、選んだ画像をその場で出す
    const reader = new FileReader()
    reader.addEventListener('load', () => {
      // 続けて別の画像を選んだときは、古い読み込みの結果でプレビューを上書きしない
      if (typeof reader.result === 'string') {
        const url = reader.result
        setPicked((current) => (current?.file === file ? { file, url } : current))
      }
    })
    reader.readAsDataURL(file)
  }

  const toggleRemoveImage = (remove: boolean) => {
    setValues((current) => ({ ...current, removeImage: remove, image: remove ? null : current.image }))
    if (remove) {
      setPicked(null)
      setFileInputKey((key) => key + 1)
    }
  }

  const preview =
    picked !== null ? picked.url : values.removeImage || currentImage === null ? null : storageImageUrl(currentImage)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSave({ kind: 'saving' })
    // 欄を離れずに Enter で送ったときも、英名を半角にして送る
    const normalized = { ...values, nameEng: toHalfWidthAlphanumeric(values.nameEng) }
    setValues(normalized)
    const result = await saveCategory(nodeId, normalized, kept)
    // 保存を待つ間に別の画面へ移っていたら、カテゴリ木へ戻さない
    if (!mounted.current) return
    if (result.kind === 'saved') {
      onSaved(normalized.name, result.warnings)
      return
    }
    setErrors(result.kind === 'invalid' ? result.errors : {})
    setSave(result)
  }

  const saving = save.kind === 'saving'
  const otherErrors = Object.entries(errors)
    .filter(([field]) => !FIELDS_ON_FORM.has(field))
    .flatMap(([, messages]) => messages)

  return (
    <form className={styles.form} noValidate onSubmit={(event) => void submit(event)}>
      {parent.kind === 'select' ? (
        <ParentSelect
          tree={parent.tree}
          selectedId={values.parentId}
          errors={errors.parent_id}
          onSelect={(id) => set('parentId', id)}
        />
      ) : (
        <div className={styles.field}>
          <span className={styles.label}>親</span>
          <p className={styles.selected}>{parent.path.length === 0 ? '(根のノード)' : parent.path.join(' / ')}</p>
          <FieldErrors messages={errors.parent_id} />
        </div>
      )}

      <div className={styles.row}>
        <TextField label="名前" errors={errors.name} value={values.name} onChange={(value) => set('name', value)} />
        <TextField
          label="英名"
          errors={errors.name_eng}
          value={values.nameEng}
          onChange={(value) => set('nameEng', value)}
          onBlur={() => set('nameEng', toHalfWidthAlphanumeric(values.nameEng))}
        />
      </div>

      <div className={styles.checks}>
        <label className={styles.check}>
          <input type="checkbox" checked={values.isMajor} onChange={(event) => set('isMajor', event.target.checked)} />
          メジャー
        </label>
        <label className={styles.check}>
          <input type="checkbox" checked={values.isLike} onChange={(event) => set('isLike', event.target.checked)} />
          お気に入り
        </label>
        <label className={styles.check}>
          <input type="checkbox" checked={values.isPowder} onChange={(event) => set('isPowder', event.target.checked)} />
          粉もの
        </label>
      </div>
      <FieldErrors messages={[...(errors.is_major ?? []), ...(errors.is_like ?? []), ...(errors.is_powder ?? [])]} />

      <Field label="説明" errors={errors.description}>
        {(props) => (
          <textarea
            {...props}
            className={styles.textarea}
            rows={8}
            value={values.description}
            onChange={(event) => set('description', event.target.value)}
          />
        )}
      </Field>

      <div className={styles.row}>
        <TextField label="YouTube" errors={errors.youtube} value={values.youtube} onChange={(value) => set('youtube', value)} />
        <TextField
          label="時"
          errors={errors.youtube_start_hour}
          value={values.youtubeStartHour}
          inputMode="numeric"
          narrow
          onChange={(value) => set('youtubeStartHour', sanitizeDigits(value, HOUR_DIGITS))}
        />
        <TextField
          label="分"
          errors={errors.youtube_start_minute}
          value={values.youtubeStartMinute}
          inputMode="numeric"
          narrow
          onChange={(value) => set('youtubeStartMinute', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
        />
        <TextField
          label="秒"
          errors={errors.youtube_start_second}
          value={values.youtubeStartSecond}
          inputMode="numeric"
          narrow
          onChange={(value) => set('youtubeStartSecond', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
        />
      </div>

      <div className={styles.image}>
        {preview !== null && <img className={styles.preview} src={preview} alt="ノードの画像" />}
        <Field label="画像" errors={[...(errors.image ?? []), ...(errors.remove_image ?? [])]}>
          {(props) => (
            <input
              {...props}
              key={fileInputKey}
              className={styles.file}
              type="file"
              accept="image/*"
              onChange={(event) => pickImage(event.target.files?.[0] ?? null)}
            />
          )}
        </Field>
        {currentImage !== null && (
          <label className={styles.check}>
            <input
              type="checkbox"
              checked={values.removeImage}
              onChange={(event) => toggleRemoveImage(event.target.checked)}
            />
            画像を消す
          </label>
        )}
      </div>

      <div className={styles.row}>
        <TextField label="リンクテキスト" errors={errors.image_txt} value={values.imageTxt} onChange={(value) => set('imageTxt', value)} />
        <TextField label="リンクURL" errors={errors.image_url} value={values.imageUrl} onChange={(value) => set('imageUrl', value)} />
      </div>

      <div className={styles.submit}>
        <button className={styles.submitButton} type="submit" disabled={saving}>
          送信
        </button>
        {save.kind !== 'idle' && save.kind !== 'saving' && (
          <div className={styles.failure} role="alert">
            <p className={styles.failureMessage}>{failureMessage(save)}</p>
            <FieldErrors messages={otherErrors} />
          </div>
        )}
      </div>
    </form>
  )
}

function ParentSelect({
  tree,
  selectedId,
  errors,
  onSelect,
}: {
  tree: readonly AdminTreeItem[]
  selectedId: number | null
  errors: readonly string[] | undefined
  onSelect: (id: number) => void
}) {
  const errorId = useId()
  const nodes = useMemo(() => tree.map(toCategoryTreeNode), [tree])
  const selectedPath = useMemo(() => (selectedId === null ? null : pathTo(tree, selectedId)), [tree, selectedId])
  const hasErrors = errors !== undefined && errors.length > 0

  return (
    <fieldset className={styles.parent} aria-describedby={hasErrors ? errorId : undefined}>
      <legend className={styles.legend}>親</legend>
      <p className={styles.selected}>
        選んでいる親: {selectedPath === null ? '(未選択)' : selectedPath.map((node) => node.name).join(' / ')}
      </p>
      <FieldErrors id={errorId} messages={errors} />
      <CategoryTree
        nodes={nodes}
        label="親"
        selection={{ mode: 'single', selectedId, onSelect: (node) => onSelect(node.id) }}
      />
    </fieldset>
  )
}

type ControlProps = { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }

/** ラベルと、検証のメッセージ(あれば)を付けた欄。メッセージは欄の説明(aria-describedby)にもする */
function Field({
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

function TextField({
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

function FieldErrors({ id, messages }: { id?: string; messages: readonly string[] | undefined }) {
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

function failureMessage(failure: Exclude<SaveState, { kind: 'idle' | 'saving' }>): string {
  switch (failure.kind) {
    case 'invalid':
      return '保存できませんでした。入力を確かめてください。'
    case 'http-error':
      // ログインの期限が切れた後の保存は 401。何度送っても同じなので、ログインし直す手順を出す。開き直すと入力が消えるので、先に控えるよう書く(RC-17)
      if (failure.status === 401) {
        return 'ログインの期限が切れていて保存できませんでした。入力を控えてから、ページを開き直してログインし直してください。'
      }
      return `保存できませんでした(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
    case 'network-error':
      return '保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
  }
}

/** 管理 API の木のノードを、木の部品のノードにする */
function toCategoryTreeNode(item: AdminTreeItem): CategoryTreeNode {
  return { id: item.id, name: item.name, name_eng: item.name_eng, is_major: item.is_major, children: item.children.map(toCategoryTreeNode) }
}

/** 根から id のノードまでのノード(根を含む)。木に無ければ null */
function pathTo(nodes: readonly AdminTreeItem[], id: number): AdminTreeItem[] | null {
  for (const node of nodes) {
    if (node.id === id) return [node]
    const found = pathTo(node.children, id)
    if (found !== null) return [node, ...found]
  }
  return null
}
