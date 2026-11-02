import { useId, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import type { components } from '../../api/schema'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { storageImageUrl } from '../../utils/storageImageUrl'
import { saveBrand, type BrandFieldErrors, type SaveBrandResult } from './brandApi'
import {
  sanitizeDigits,
  sanitizeStrength,
  STRENGTH_MAX_LENGTH,
  toHalfWidthAlphanumeric,
  type BrandFormValues,
} from './brandFormValues'
import styles from './BrandForm.module.css'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

type Props = {
  /** 編集する銘柄の ID。新規なら null */
  brandId: number | null
  /** 最初の入力(新規は空、編集は開いた銘柄の値) */
  initialValues: BrandFormValues
  /** 今の画像(API の image の値。public ディスクのファイル名か外部の URL)。無ければ null */
  currentImage: string | null
  /** 所属を選ぶ木(GET /api/search-form の categories。根も含めた木全体) */
  categories: readonly SearchFormNode[]
  /** 保存できたときに呼ぶ(画面は一覧へ戻る) */
  onSaved: () => void
}

type SaveState = { kind: 'idle' } | { kind: 'saving' } | Exclude<SaveBrandResult, { kind: 'saved' }>

/** 開始位置の欄に入れられる桁(旧と同じく、時は 1 桁、分・秒は 2 桁) */
const HOUR_DIGITS = 1
const MINUTE_OR_SECOND_DIGITS = 2

/**
 * 管理画面の銘柄の編集フォーム(旧 `Detail/BrandEdit.vue`・`component/NamesAndDescription.vue`。#64)。
 *
 * 並びは旧(本番の画面のビルドも同じ)と同じく、所属 / 名前・英名・度数 / メジャー・お気に入り / 説明 / YouTube と開始位置 / 画像 /
 * リンクテキスト・リンクURL / 送信(docs/decisions/admin-brands.md の決まったこと 3)。
 * - 所属は木の部品(#297)の単一選択で、根(旧の種別)の下のどの深さのノードでも選べる。根は見出しにして選べなくする
 *   (旧はカテゴリとミドルの 2 つの選択。#314 の「旧との差分」)
 * - 度数は数字と `.` だけを 4 文字まで(旧は 4 文字で止めなかった)、開始位置は数字だけを時 1 桁・分秒 2 桁まで受け付ける(旧と同じ)
 * - メジャー・お気に入りは、押すたびに入れ・外しができる(旧は押すと必ず入れるだけで、外せなかった)
 * - 英名は、欄を離れたときに全角の英数字を半角にする(旧と同じ)
 * - 検証は API に任せ、422 は項目ごとに欄の下に出し、画面を移らない。入力は残す(docs/decisions/admin-screens.md の既定。
 *   旧はサーバーのメッセージを alert で出していた)
 *
 * 名前・英名の重なりを入れたそばに出す(旧の duplicate_check)のは、問い合わせる API(#632)ができてから #633 で足す。
 */
export default function BrandForm({ brandId, initialValues, currentImage, categories, onSaved }: Props) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState<BrandFieldErrors>({})
  const [save, setSave] = useState<SaveState>({ kind: 'idle' })
  const [preview, setPreview] = useState<string | null>(currentImage === null ? null : storageImageUrl(currentImage))
  const nodes = useMemo(() => categories.map(toCategoryTreeNode), [categories])
  const rootIds = useMemo(() => new Set(categories.map((root) => root.id)), [categories])
  const appearance = useMemo<CategoryTreeAppearance>(() => ({ isHeading: (node) => rootIds.has(node.id) }), [rootIds])
  const selectedPath = useMemo(
    () => (values.categoryId === null ? null : pathTo(categories, values.categoryId)),
    [categories, values.categoryId],
  )

  const set = <K extends keyof BrandFormValues>(key: K, value: BrandFormValues[K]) =>
    setValues((current) => ({ ...current, [key]: value }))

  const pickImage = (file: File | null) => {
    set('image', file)
    if (file === null) {
      setPreview(currentImage === null ? null : storageImageUrl(currentImage))
      return
    }
    // 旧と同じく、選んだ画像をその場で出す
    const reader = new FileReader()
    reader.addEventListener('load', () => {
      if (typeof reader.result === 'string') setPreview(reader.result)
    })
    reader.readAsDataURL(file)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSave({ kind: 'saving' })
    const result = await saveBrand(brandId, values)
    if (result.kind === 'saved') {
      onSaved()
      return
    }
    setErrors(result.kind === 'invalid' ? result.errors : {})
    setSave(result)
  }

  const saving = save.kind === 'saving'

  return (
    <form className={styles.form} noValidate onSubmit={(event) => void submit(event)}>
      <fieldset className={styles.category}>
        <legend className={styles.legend}>所属</legend>
        <p className={styles.selected}>
          選んでいる所属: {selectedPath === null ? '(未選択)' : selectedPath.map((node) => node.name).join(' / ')}
        </p>
        <FieldErrors messages={errors.category_id} />
        <CategoryTree
          nodes={nodes}
          label="所属"
          appearance={appearance}
          selection={{
            mode: 'single',
            selectedId: values.categoryId,
            onSelect: (node) => set('categoryId', node.id),
            isSelectable: (node) => !rootIds.has(node.id),
          }}
        />
      </fieldset>

      <div className={styles.row}>
        <TextField label="名前" errors={errors.name} value={values.name} onChange={(value) => set('name', value)} />
        <TextField
          label="英名"
          errors={errors.name_eng}
          value={values.nameEng}
          onChange={(value) => set('nameEng', value)}
          onBlur={() => set('nameEng', toHalfWidthAlphanumeric(values.nameEng))}
        />
        <TextField
          label="度数(%)"
          errors={errors.strength}
          value={values.strength}
          inputMode="decimal"
          maxLength={STRENGTH_MAX_LENGTH}
          narrow
          onChange={(value) => set('strength', sanitizeStrength(value))}
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
      </div>
      <FieldErrors messages={[...(errors.is_major ?? []), ...(errors.is_like ?? [])]} />

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
          maxLength={HOUR_DIGITS}
          narrow
          onChange={(value) => set('youtubeStartHour', sanitizeDigits(value, HOUR_DIGITS))}
        />
        <TextField
          label="分"
          errors={errors.youtube_start_minute}
          value={values.youtubeStartMinute}
          inputMode="numeric"
          maxLength={MINUTE_OR_SECOND_DIGITS}
          narrow
          onChange={(value) => set('youtubeStartMinute', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
        />
        <TextField
          label="秒"
          errors={errors.youtube_start_second}
          value={values.youtubeStartSecond}
          inputMode="numeric"
          maxLength={MINUTE_OR_SECOND_DIGITS}
          narrow
          onChange={(value) => set('youtubeStartSecond', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
        />
      </div>

      <div className={styles.image}>
        {preview !== null && <img className={styles.preview} src={preview} alt="銘柄の画像" />}
        <Field label="画像" errors={errors.image}>
          {(props) => (
            <input
              {...props}
              className={styles.file}
              type="file"
              accept="image/*"
              onChange={(event) => pickImage(event.target.files?.[0] ?? null)}
            />
          )}
        </Field>
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
          <p className={styles.failure} role="alert">
            {failureMessage(save)}
          </p>
        )}
      </div>
    </form>
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
  maxLength,
  narrow,
}: {
  label: string
  errors: readonly string[] | undefined
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  inputMode?: 'decimal' | 'numeric'
  maxLength?: number
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
          maxLength={maxLength}
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
      return `保存できませんでした(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
    case 'network-error':
      return '保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
  }
}

/** API の木のノードを、木の部品のノードにする(検索フォームの木は英名を持たない) */
function toCategoryTreeNode(node: SearchFormNode): CategoryTreeNode {
  return { id: node.id, name: node.name, name_eng: null, is_major: node.is_major, children: node.children.map(toCategoryTreeNode) }
}

/** 根から id のノードまでのノード(根を除く)。木に無ければ null */
function pathTo(nodes: readonly SearchFormNode[], id: number): SearchFormNode[] | null {
  for (const root of nodes) {
    const found = findPath(root, id)
    if (found !== null) return found.slice(1)
  }
  return null
}

function findPath(node: SearchFormNode, id: number): SearchFormNode[] | null {
  if (node.id === id) return [node]
  for (const child of node.children) {
    const found = findPath(child, id)
    if (found !== null) return [node, ...found]
  }
  return null
}
