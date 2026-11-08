import { useId, useMemo, useState, type FormEvent } from 'react'
import type { components } from '../../api/schema'
import { Field, FieldErrors, TextAreaField, TextField } from '../../components/AdminForm/AdminField'
import { useDuplicateWarning } from '../../components/AdminForm/useDuplicateWarning'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import { storageImageUrl } from '../../utils/storageImageUrl'
import { sanitizeDigits, toHalfWidthAlphanumeric } from '../admin-brands/brandFormValues'
import { pathTo, ROLE_OPTIONS, roleLabel, toCategoryTreeNode } from './adminCategoryTree'
import { findCategoryDuplicate, saveCategory, type CategoryFieldErrors, type CategorySaveWarning, type SaveCategoryResult } from './categoryApi'
import type { CategoryFormValues, CategoryKeptValues } from './categoryFormValues'
import styles from './CategoryForm.module.css'
import { useMounted } from './useMounted'

type AdminTreeItem = components['schemas']['CategoryTreeItemResource']
type CategoryRole = components['schemas']['CategoryRole']

/**
 * 親の欄。新規は木から選ぶか「親なし」(根を作る。#310)を選び、編集は今の親(根から親までの名前)を出すだけにする
 * (フォームの親の欄で選び直すのは #675 で足す。移すのは編集画面の下の「親の付け替え」の欄。#125)
 */
export type ParentField =
  | { kind: 'select'; tree: readonly AdminTreeItem[] }
  | { kind: 'fixed'; path: readonly string[] }

type Props = {
  /** 編集するノードの ID。新規なら null */
  nodeId: number | null
  /** 最初の入力(新規は空、編集は開いたノードの値) */
  initialValues: CategoryFormValues
  /** このフォームでは変えずに送り直す値(有名な銘柄) */
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

/** 欄の下に出す検証のメッセージの項目。ここに無い項目(有名な銘柄など)のメッセージは、送信の下にまとめて出す */
const FIELDS_ON_FORM = new Set([
  'parent_id',
  'role',
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
 * (docs/decisions/admin-category-form.md)。根のときだけ、親の下に役割の欄を出す(#310)。
 * - 親は、新規なら木(#123 と同じ管理 API の木)から 1 つ選ぶ。根も選べる。「親なし」を選ぶと根を作る(#310)
 * - 役割(なし・ソフトドリンク・その他)は根のときだけ出す。根以外では出さず、送らない(役割を持てるのは根だけ。#153)
 * - 説明は、API が返す編集の形(記法に指す先の名前を添えたもの。#287)のまま編集し、そのまま送る
 * - 開始位置は数字だけを時 1 桁・分秒 2 桁まで受け付ける(旧と同じ)。英名は欄を離れたときに全角の英数字を半角にする(旧と同じ)
 * - メジャー・お気に入り・粉ものは、押すたびに入れ・外しができる(旧は押すと必ず入れるだけで、外せなかった)
 * - 画像は選ぶとその場で出し、差し替えられる。今の画像があれば「画像を消す」で画像だけを消せる(#288)
 * - 検証は API に任せ、422 は項目ごとに欄の下に出し、画面を移らない。入力は残す(docs/decisions/admin-screens.md の既定)
 *
 * 有名な銘柄の選択・その場での作成は #309 で足す。それまでは今の値のまま送り直す(CategoryKeptValues)。
 * 名前・英名は、値を変えて欄を離れたら同じ親の下の重なりを問い合わせ(#673 の API)、重なれば欄の下に「同じ名前が存在しています」を出す
 * (旧の duplicate_check。#674)。知らせるだけで送信は止めず、名前の重なりで保存を止めるのは保存の API の 422 に任せる。
 */
export default function CategoryForm({ nodeId, initialValues, kept, currentImage, parent, onSaved }: Props) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState<CategoryFieldErrors>({})
  // 重なりは、保存する親の下で比べる(根なら根どうし)。新規で「親なし」を選んだら、前に選んでいた親ではなく根どうしで比べる。
  // 新規で親をまだ選んでいなければ、比べる相手が決まらないので比べない
  const duplicateScope = values.isRoot ? { key: null } : values.parentId !== null ? { key: values.parentId } : null
  const nameDuplicate = useDuplicateWarning<number | null>(
    (name, parentId) => findCategoryDuplicate('name', name, parentId, nodeId),
    duplicateScope,
    initialValues.name,
  )
  const nameEngDuplicate = useDuplicateWarning<number | null>(
    (nameEng, parentId) => findCategoryDuplicate('name_eng', nameEng, parentId, nodeId),
    duplicateScope,
    initialValues.nameEng,
  )
  const [save, setSave] = useState<SaveState>({ kind: 'idle' })
  const [picked, setPicked] = useState<{ file: File; url: string | null } | null>(null)
  // 「画像を消す」を入れたときに、選んでいたファイルを欄からも外すため、ファイルの欄を作り直す
  const [fileInputKey, setFileInputKey] = useState(0)

  const set = <K extends keyof CategoryFormValues>(key: K, value: CategoryFormValues[K]) =>
    setValues((current) => ({ ...current, [key]: value }))

  const mounted = useMounted()

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
          isRoot={values.isRoot}
          errors={errors.parent_id}
          onSelect={(id) => set('parentId', id)}
          onRootChange={(isRoot) => set('isRoot', isRoot)}
        />
      ) : (
        <div className={styles.fixedParent}>
          <span className={styles.fixedParentLabel}>親</span>
          <p className={styles.selected}>{parent.path.length === 0 ? '(根のノード)' : parent.path.join(' / ')}</p>
          <FieldErrors messages={errors.parent_id} />
        </div>
      )}

      {values.isRoot && <RoleSelect role={values.role} errors={errors.role} onChange={(role) => set('role', role)} />}

      <div className={styles.row}>
        <TextField
          label="名前"
          errors={errors.name}
          warning={nameDuplicate.warning}
          value={values.name}
          onChange={(value) => set('name', value)}
          onBlur={() => nameDuplicate.check(values.name)}
        />
        <TextField
          label="英名"
          errors={errors.name_eng}
          warning={nameEngDuplicate.warning}
          value={values.nameEng}
          onChange={(value) => set('nameEng', value)}
          onBlur={() => {
            // 保存するのは半角にした値なので、重なりも半角にした値で問い合わせる
            const nameEng = toHalfWidthAlphanumeric(values.nameEng)
            set('nameEng', nameEng)
            nameEngDuplicate.check(nameEng)
          }}
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

      <TextAreaField
        label="説明"
        errors={errors.description}
        rows={8}
        value={values.description}
        onChange={(value) => set('description', value)}
      />

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
  isRoot,
  errors,
  onSelect,
  onRootChange,
}: {
  tree: readonly AdminTreeItem[]
  selectedId: number | null
  /** 「親なし」(根を作る)を選んでいるか。選んでいるあいだは木を出さない */
  isRoot: boolean
  errors: readonly string[] | undefined
  onSelect: (id: number) => void
  onRootChange: (isRoot: boolean) => void
}) {
  const errorId = useId()
  const nodes = useMemo(() => tree.map(toCategoryTreeNode), [tree])
  const selectedPath = useMemo(() => (selectedId === null ? null : pathTo(tree, selectedId)), [tree, selectedId])
  const hasErrors = errors !== undefined && errors.length > 0

  return (
    <fieldset className={styles.parent} aria-describedby={hasErrors ? errorId : undefined}>
      <legend className={styles.legend}>親</legend>
      <label className={`${styles.check} ${styles.rootCheck}`}>
        <input type="checkbox" checked={isRoot} onChange={(event) => onRootChange(event.target.checked)} />
        親なし(根のノードを作る)
      </label>
      {!isRoot && (
        <p className={styles.selected}>
          選んでいる親: {selectedPath === null ? '(未選択)' : selectedPath.map((node) => node.name).join(' / ')}
        </p>
      )}
      <FieldErrors id={errorId} messages={errors} />
      {/* 根を作るあいだは木を出さない。「親なし」を外すと、前に選んでいた親のまま木が戻る */}
      {!isRoot && (
        <CategoryTree
          nodes={nodes}
          label="親"
          selection={{ mode: 'single', selectedId, onSelect: (node) => onSelect(node.id) }}
        />
      )}
    </fieldset>
  )
}

/** 根の役割の欄(なし・ソフトドリンク・その他。#310)。根のときだけ出す */
function RoleSelect({
  role,
  errors,
  onChange,
}: {
  role: CategoryRole | null
  errors: readonly string[] | undefined
  onChange: (role: CategoryRole | null) => void
}) {
  const name = useId()
  const errorId = useId()
  const hasErrors = errors !== undefined && errors.length > 0
  return (
    <fieldset className={styles.parent} aria-describedby={hasErrors ? errorId : undefined}>
      <legend className={styles.legend}>役割</legend>
      <div className={styles.checks}>
        {ROLE_OPTIONS.map((option) => (
          <label key={option ?? 'none'} className={styles.check}>
            <input type="radio" name={name} checked={role === option} onChange={() => onChange(option)} />
            {roleLabel(option)}
          </label>
        ))}
      </div>
      <FieldErrors id={errorId} messages={errors} />
    </fieldset>
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
