import { useId, useRef, useState, type ReactNode } from 'react'
import { sanitizeDigits, sanitizeStrength, toHalfWidthAlphanumeric } from '../admin-brands/brandFormValues'
import type { CategoryFieldErrors } from './categoryApi'
import type { FamousBrandCandidate, FamousBrandValues, NewBrandValues } from './famousBrandValues'
import fieldStyles from '../../components/AdminForm/AdminField.module.css'
import styles from './CategoryForm.module.css'

type Props = {
  /** 選べる銘柄(このノードの部分木の銘柄。名前の順) */
  candidates: readonly FamousBrandCandidate[]
  values: FamousBrandValues
  errors: CategoryFieldErrors
  onChange: (values: FamousBrandValues) => void
}

/** 選択の欄の「新しく作成する」の値(銘柄の ID は 1 から始まるので、空の文字で区別する) */
const CREATE_NEW = ''

/** 開始位置の欄に入れられる桁(ノードの欄と同じく、時は 1 桁、分・秒は 2 桁) */
const HOUR_DIGITS = 1
const MINUTE_OR_SECOND_DIGITS = 2

/** その場で作る銘柄の欄の名前の前に付ける語。ノードの欄と見分けられるよう、読み上げる名前(aria-label)に付ける */
const PREFIX = '有名な銘柄の'

/**
 * ノードの編集フォームの有名な銘柄の欄(旧 `Detail/component/FamousBrandSelect.vue`。#309)。
 *
 * - 候補(そのノードの部分木の銘柄。#158)から選ぶ。先頭は「(新しく作成する)」で、続けて「名前(英名)」を名前の順に並べる(旧と同じ)
 * - 候補が 0 件なら選択の欄を出さず、「-有名な銘柄を入力して下さい-」と出す(旧と同じ)
 * - 「新しく作成する」のときは、その場で作る銘柄の欄(銘柄のフォームと同じ並び)を出す。作った銘柄は、このノードの直下の銘柄になり、
 *   有名な銘柄に入る(API の AdminCategoryWriter)
 * - 必須かどうかは API が根の役割で決め(#155・#158)、画面では確かめない(docs/decisions/admin-category-form.md の決まったこと 7)
 */
export default function FamousBrandField({ candidates, values, errors, onChange }: Props) {
  const brand = values.newBrand
  const set = <K extends keyof NewBrandValues>(key: K, value: NewBrandValues[K]) =>
    onChange({ ...values, newBrand: { ...brand, [key]: value } })
  const selectId = useId()
  const selectErrorId = useId()
  const hasSelectErrors = (errors.famous_brand_id ?? []).length > 0
  // 既存の銘柄を選ぶと、その場で作る銘柄の欄は隠れて、ファイルの欄の選択も消える。選んでいた画像を送らないよう外しておく
  // (文字の欄の入力は残し、「新しく作成する」に戻すとそのまま出る)
  const select = (selectedId: number | null) =>
    onChange({ selectedId, newBrand: selectedId === null ? brand : { ...brand, image: null } })

  return (
    <fieldset className={styles.parent}>
      <legend className={styles.legend}>有名な銘柄</legend>
      {candidates.length === 0 ? (
        <p className={styles.selected}>-有名な銘柄を入力して下さい-</p>
      ) : (
        <div className={fieldStyles.field}>
          <label className={fieldStyles.label} htmlFor={selectId}>
            有名な銘柄
          </label>
          <select
            id={selectId}
            className={fieldStyles.input}
            value={values.selectedId === null ? CREATE_NEW : String(values.selectedId)}
            aria-invalid={hasSelectErrors}
            aria-describedby={hasSelectErrors ? selectErrorId : undefined}
            onChange={(event) => select(event.target.value === CREATE_NEW ? null : Number(event.target.value))}
          >
            <option value={CREATE_NEW}>(新しく作成する)</option>
            {candidates.map((candidate) => (
              <option key={candidate.id} value={String(candidate.id)}>
                {candidate.name}({candidate.name_eng})
              </option>
            ))}
          </select>
        </div>
      )}
      {/* 候補が無いときも、有名な銘柄の誤り(部分木の外の銘柄など)はここに出す */}
      <FieldErrors id={selectErrorId} messages={errors.famous_brand_id} />

      {values.selectedId === null && (
        <div className={styles.form}>
          <div className={styles.row}>
            <TextField label="名前" errors={errors.brand_name} value={brand.name} onChange={(value) => set('name', value)} />
            <TextField
              label="英名"
              errors={errors.brand_name_eng}
              value={brand.nameEng}
              onChange={(value) => set('nameEng', value)}
              onBlur={() => set('nameEng', toHalfWidthAlphanumeric(brand.nameEng))}
            />
            <TextField
              label="度数(%)"
              errors={errors.brand_strength}
              value={brand.strength}
              inputMode="decimal"
              narrow
              onChange={(value) => set('strength', sanitizeStrength(value))}
            />
          </div>

          <div className={styles.checks}>
            <label className={styles.check}>
              <input
                type="checkbox"
                aria-label={`${PREFIX}メジャー`}
                checked={brand.isMajor}
                onChange={(event) => set('isMajor', event.target.checked)}
              />
              メジャー
            </label>
            <label className={styles.check}>
              <input
                type="checkbox"
                aria-label={`${PREFIX}お気に入り`}
                checked={brand.isLike}
                onChange={(event) => set('isLike', event.target.checked)}
              />
              お気に入り
            </label>
          </div>
          <FieldErrors messages={[...(errors.brand_is_major ?? []), ...(errors.brand_is_like ?? [])]} />

          <Field label="説明" errors={errors.brand_description}>
            {(props) => (
              <textarea
                {...props}
                className={fieldStyles.textarea}
                rows={8}
                value={brand.description}
                onChange={(event) => set('description', event.target.value)}
              />
            )}
          </Field>

          <div className={styles.row}>
            <TextField label="YouTube" errors={errors.brand_youtube} value={brand.youtube} onChange={(value) => set('youtube', value)} />
            <TextField
              label="時"
              name="開始位置(時)"
              errors={errors.brand_youtube_start_hour}
              value={brand.youtubeStartHour}
              inputMode="numeric"
              narrow
              onChange={(value) => set('youtubeStartHour', sanitizeDigits(value, HOUR_DIGITS))}
            />
            <TextField
              label="分"
              name="開始位置(分)"
              errors={errors.brand_youtube_start_minute}
              value={brand.youtubeStartMinute}
              inputMode="numeric"
              narrow
              onChange={(value) => set('youtubeStartMinute', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
            />
            <TextField
              label="秒"
              name="開始位置(秒)"
              errors={errors.brand_youtube_start_second}
              value={brand.youtubeStartSecond}
              inputMode="numeric"
              narrow
              onChange={(value) => set('youtubeStartSecond', sanitizeDigits(value, MINUTE_OR_SECOND_DIGITS))}
            />
          </div>

          <BrandImage image={brand.image} errors={errors.brand_image} onPick={(file) => set('image', file)} />

          <div className={styles.row}>
            <TextField label="リンクテキスト" errors={errors.brand_image_txt} value={brand.imageTxt} onChange={(value) => set('imageTxt', value)} />
            <TextField label="リンクURL" errors={errors.brand_image_url} value={brand.imageUrl} onChange={(value) => set('imageUrl', value)} />
          </div>
        </div>
      )}
    </fieldset>
  )
}

/** その場で作る銘柄の画像。選ぶとその場で出す(旧と同じ) */
function BrandImage({
  image,
  errors,
  onPick,
}: {
  image: File | null
  errors: readonly string[] | undefined
  onPick: (file: File | null) => void
}) {
  const [preview, setPreview] = useState<{ file: File; url: string } | null>(null)

  // いま選んでいる画像。読み込みが前後して終わっても、古い画像の結果でプレビューを上書きしない
  const latest = useRef<File | null>(null)

  const pick = (file: File | null) => {
    latest.current = file
    onPick(file)
    if (file === null) return
    const reader = new FileReader()
    reader.addEventListener('load', () => {
      if (latest.current === file && typeof reader.result === 'string') {
        const url = reader.result
        setPreview({ file, url })
      }
    })
    reader.readAsDataURL(file)
  }

  // 続けて別の画像を選んだときは、前に選んだ画像の読み込みの結果を出さない
  const url = preview !== null && preview.file === image ? preview.url : null

  return (
    <div className={styles.image}>
      {url !== null && <img className={styles.preview} src={url} alt="有名な銘柄の画像" />}
      <Field label="画像" errors={errors}>
        {(props) => (
          <input
            {...props}
            className={styles.file}
            type="file"
            accept="image/*"
            onChange={(event) => pick(event.target.files?.[0] ?? null)}
          />
        )}
      </Field>
    </div>
  )
}

type ControlProps = { 'aria-label': string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }

/**
 * ラベルと、検証のメッセージ(あれば)を付けた欄(ノードの欄の Field と同じ作り)。
 * 見えるラベルはノードの欄と同じ短い名前にし、読み上げる名前(aria-label)には「有名な銘柄の」を付けて、ノードの欄と見分けられるようにする
 * (name を渡したら、読み上げる名前はラベルの代わりに name を使う。API のメッセージの欄の名前と同じにする)
 */
function Field({
  label,
  name = label,
  errors,
  narrow = false,
  children,
}: {
  label: string
  name?: string
  errors: readonly string[] | undefined
  narrow?: boolean
  children: (props: ControlProps) => ReactNode
}) {
  const errorId = useId()
  const hasErrors = errors !== undefined && errors.length > 0
  return (
    <div className={narrow ? `${fieldStyles.field} ${fieldStyles.narrow}` : fieldStyles.field}>
      {/* ノードの欄のラベル(「名前」など)と取り違えないよう、label 要素にはせず、読み上げる名前は欄の aria-label にする */}
      <span className={fieldStyles.label} aria-hidden="true">
        {label}
      </span>
      {children({
        'aria-label': `${PREFIX}${name}`,
        'aria-invalid': hasErrors,
        'aria-describedby': hasErrors ? errorId : undefined,
      })}
      <FieldErrors id={errorId} messages={errors} />
    </div>
  )
}

function TextField({
  label,
  name,
  errors,
  value,
  onChange,
  onBlur,
  inputMode,
  narrow,
}: {
  label: string
  name?: string
  errors: readonly string[] | undefined
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  inputMode?: 'numeric' | 'decimal'
  narrow?: boolean
}) {
  return (
    <Field label={label} name={name} errors={errors} narrow={narrow}>
      {(props) => (
        <input
          {...props}
          className={fieldStyles.input}
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
    <ul id={id} className={fieldStyles.errors}>
      {messages.map((message, index) => (
        // 同じ文が 2 度来ることがある(メジャー・お気に入りの両方が必須で落ちたときなど)ので、並びの位置で区別する
        <li key={index}>{message}</li>
      ))}
    </ul>
  )
}
