import { useCallback, useEffect, useId, useMemo, useRef, useState, type FormEvent } from 'react'
import type { components, paths } from '../../api/schema'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { storageImageUrl } from '../../utils/storageImageUrl'
import { sanitizeDigits, toHalfWidthAlphanumeric } from '../admin-brands/brandFormValues'
import CocktailAliasesField from './CocktailAliasesField'
import CocktailDuplicates from './CocktailDuplicates'
import {
  deleteCocktail,
  saveCocktail,
  type CocktailAliasWarning,
  type CocktailFieldErrors,
  type DeleteCocktailResult,
  type SaveCocktailResult,
} from './cocktailApi'
import { checkIngredientRows } from './checkIngredientRows'
import { Field, FieldErrors, SelectField, TextField, type SelectOption } from './CocktailFormFields'
import {
  cocktailWordSearchUrl,
  meaningSearchUrl,
  RATE_OPTIONS,
  SWEET_DEGREE_OPTIONS,
  type CocktailFormValues,
} from './cocktailFormValues'
import { editIngredients, type IngredientEdit } from './editIngredients'
import IngredientRows from './IngredientRows'
import { indexNodePaths } from '../admin-paraphrases/paraphraseTarget'
import { clearIngredientErrors } from './clearIngredientErrors'
import type { IngredientErrors } from './ingredientErrors.types'
import { splitIngredientErrors } from './splitIngredientErrors'
import { useCocktailDuplicates } from './useCocktailDuplicates'
import styles from './CocktailForm.module.css'

type SearchFormNode = components['schemas']['SearchFormNodeResource']
export type CocktailFormOptions = paths['/admin/cocktail-form-options']['get']['responses'][200]['content']['application/json']

type Props = {
  /** 編集するカクテルの ID。新規なら null */
  cocktailId: number | null
  /** 最初の入力(新規は空、編集は開いたカクテルの値) */
  initialValues: CocktailFormValues
  /** 今の画像(API の image の値。public ディスクのファイル名か外部の URL)。無ければ null */
  currentImage: string | null
  /** ベースを選ぶ木(GET /api/search-form の categories。根も含めた木全体) */
  categories: readonly SearchFormNode[]
  /** 技法・グラス・氷の選択肢(GET /api/admin/cocktail-form-options) */
  options: CocktailFormOptions
  /**
   * 保存できたときに呼ぶ。isDraft は押したボタン(画面は、下書きなら下書きの一覧へ、公開なら公開済みの一覧へ戻る)。
   * name は保存したカクテルの名前、warnings は別名がほかのカクテルの名前・別名と同じだったときの警告(#83。無ければ空)
   */
  onSaved: (isDraft: boolean, name: string, warnings: readonly CocktailAliasWarning[]) => void
  /** 消せたときに呼ぶ(画面はメニューへ移る) */
  onDeleted: () => void
}

type Busy = { kind: 'idle' } | { kind: 'saving' } | { kind: 'deleting' }
type SaveFailure = Exclude<SaveCocktailResult, { kind: 'saved' }>
type DeleteFailure = Exclude<DeleteCocktailResult, { kind: 'deleted' }>
type Failure = { kind: 'none' } | { kind: 'save'; failure: SaveFailure } | { kind: 'delete'; failure: DeleteFailure }

/** 開始位置の欄に入れられる桁(旧と同じく、時は 1 桁、分・秒は 2 桁。銘柄のフォームと同じ) */
const HOUR_DIGITS = 1
const MINUTE_OR_SECOND_DIGITS = 2

/** 欄の下に出す検証のメッセージの項目(API の項目名)。これ以外の項目のメッセージは、保存のボタンの下にまとめて出す */
const FIELD_KEYS = new Set([
  'base_category_id',
  'ice_id',
  'glass_dictionary_id',
  'name',
  'name_eng',
  'method_id',
  'rate',
  'sweet_degree',
  'is_major',
  'description',
  'note',
  'youtube',
  'youtube_start_hour',
  'youtube_start_minute',
  'youtube_start_second',
  'image',
  'image_txt',
  'image_url',
])

/** 別名のメッセージの項目(`aliases`・`aliases.0` など)。別名の欄に出す(#83) */
const isAliasKey = (key: string) => key === 'aliases' || key.startsWith('aliases.')

const toOptions = (items: readonly { id: string | number; name: string }[]): SelectOption[] =>
  items.map((item) => ({ value: String(item.id), label: item.name }))

const numberOptions = (values: readonly number[]): SelectOption[] => values.map((value) => ({ value: String(value), label: String(value) }))

const RATE_SELECT = numberOptions(RATE_OPTIONS)
const SWEET_DEGREE_SELECT = numberOptions(SWEET_DEGREE_OPTIONS)

const NO_INGREDIENT_ERRORS: IngredientErrors = { section: [], rows: new Map() }

/**
 * 管理画面のカクテルの基本情報のフォーム(旧 `Admin/Super/Detail/CocktailEdit.vue`・`component/NamesAndDescription.vue`。#127)。
 *
 * 並びは旧と同じく、(同じ名前のカクテルがあれば左に一覧)/ ベース / 氷・グラス / 名前・英名 / 外の検索サイトのリンク 2 つ /
 * 製法・評価・甘さ・メジャー / 説明 / 補足 / YouTube と開始位置 / 画像 / リンクテキスト・リンクURL / 材料 / 保存・削除。
 * 旧に無い別名の欄(#83)は、外の検索サイトのリンクと製法のあいだに置く。
 * - ベースは木の部品(#297)の単一選択で、根の下のどの深さのノードでも選べる。根は見出しにして選べなくする(#153)
 * - 保存のボタンは「下書きとして保存」「公開する」の 2 つ(docs/decisions/admin-screens.md の 1)
 * - 検証は API に任せ、422 は項目ごとに欄の下に出し、画面を移らない。入力は残す(docs/decisions/admin-screens.md の既定)。
 *   材料の行の 2 つの確かめ(レシピの重複・ベースが材料に無い)だけは API に無いので、旧と同じく送る前に画面で確かめる
 * - 削除は確かめてから消す(同じく既定)。ほかの説明文から参照されていて消せなければ(409)、理由と参照している行を出す
 * - 材料の行は、足す・消す・並べ替えるができる(#128)
 * - 別名は行を足す・消すができ、保存のときに欄の並びに置き換える(#83)。ほかのカクテルの名前・別名と同じ別名は保存したうえで、
 *   警告を onSaved に渡す(一覧へ戻った先に出す)
 */
export default function CocktailForm({ cocktailId, initialValues, currentImage, categories, options, onSaved, onDeleted }: Props) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState<CocktailFieldErrors>({})
  const [ingredientErrors, setIngredientErrors] = useState<IngredientErrors>(NO_INGREDIENT_ERRORS)
  const [busy, setBusy] = useState<Busy>({ kind: 'idle' })
  const [failure, setFailure] = useState<Failure>({ kind: 'none' })
  const [preview, setPreview] = useState<string | null>(currentImage === null ? null : storageImageUrl(currentImage))
  const baseErrorId = useId()
  const duplicates = useCocktailDuplicates(values.name, values.nameEng, cocktailId)

  const nodes = useMemo(() => categories.map(toCategoryTreeNode), [categories])
  const rootIds = useMemo(() => new Set(categories.map((root) => root.id)), [categories])
  const appearance = useMemo<CategoryTreeAppearance>(() => ({ isHeading: (node) => rootIds.has(node.id) }), [rootIds])
  // ベース・材料のノードとも、根(旧の種別)は選べない(#153)
  const isSelectable = useCallback((node: CategoryTreeNode) => !rootIds.has(node.id), [rootIds])
  const nodePaths = useMemo(() => indexNodePaths(categories), [categories])
  // 選んでいるノードは、根を除いたパンくずで出す
  const pathOf = useCallback(
    (categoryId: number) =>
      nodePaths
        .get(categoryId)
        ?.slice(1)
        .map((node) => node.name)
        .join(' / ') ?? null,
    [nodePaths],
  )
  const methodNames = useMemo(() => new Map(options.methods.map((method) => [String(method.id), method.name])), [options.methods])

  const set = <K extends keyof CocktailFormValues>(key: K, value: CocktailFormValues[K]) =>
    setValues((current) => ({ ...current, [key]: value }))

  /** 選んだ項目の前のメッセージを外す(直した欄に、前の誤りを出し続けないため。別名の欄だけ) */
  const clearErrors = (matches: (key: string) => boolean) =>
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !matches(key))))

  const editIngredient = (edit: IngredientEdit) => {
    const next = editIngredients(values, edit)
    setValues(next)
    setIngredientErrors((current) => clearIngredientErrors(current, values.ingredients, next.ingredients))
  }

  // 押したボタン。Enter で送ったときは、先頭のボタン(下書きとして保存)を押したことになる
  const savesAsDraft = useRef(true)
  const selectedFile = useRef<File | null>(null)
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const pickImage = (file: File | null) => {
    set('image', file)
    selectedFile.current = file
    if (file === null) {
      setPreview(currentImage === null ? null : storageImageUrl(currentImage))
      return
    }
    // 旧と同じく、選んだ画像をその場で出す
    const reader = new FileReader()
    reader.addEventListener('load', () => {
      // 続けて別の画像を選んだときは、古い読み込みの結果でプレビューを上書きしない
      if (selectedFile.current === file && typeof reader.result === 'string') setPreview(reader.result)
    })
    reader.readAsDataURL(file)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const isDraft = savesAsDraft.current
    // 欄を離れずに Enter で送ったときも、英名を半角にして送る
    const normalized = { ...values, nameEng: toHalfWidthAlphanumeric(values.nameEng) }
    setValues(normalized)
    const blockers = checkIngredientRows(normalized.ingredients, normalized.baseCategoryId, nodePaths)
    if (blockers.length > 0) {
      setErrors({})
      setIngredientErrors({ section: blockers, rows: new Map() })
      setFailure({ kind: 'save', failure: { kind: 'invalid', errors: {} } })
      return
    }
    setBusy({ kind: 'saving' })
    // 誤りの「材料 N 行目」は送った並びの位置なので、送ったときの行で結び付ける(待つ間に並べ替えても、送った行に付ける)
    const sentKeys = normalized.ingredients.map((row) => row.key)
    const result = await saveCocktail(cocktailId, normalized, isDraft)
    // 保存を待つ間に別の画面へ移っていたら、一覧へ戻さない
    if (!mounted.current) return
    if (result.kind === 'saved') {
      onSaved(isDraft, result.name, result.warnings)
      return
    }
    const split = splitIngredientErrors(result.kind === 'invalid' ? result.errors : {}, sentKeys)
    setErrors(split.fieldErrors)
    setIngredientErrors(split.ingredientErrors)
    setFailure({ kind: 'save', failure: result })
    setBusy({ kind: 'idle' })
  }

  const remove = async (id: number) => {
    if (!window.confirm(`「${initialValues.name}」を削除します。よろしいですか?`)) {
      return
    }
    setBusy({ kind: 'deleting' })
    const result = await deleteCocktail(id)
    if (!mounted.current) return
    if (result.kind === 'deleted') {
      onDeleted()
      return
    }
    setFailure({ kind: 'delete', failure: result })
    setBusy({ kind: 'idle' })
  }

  const disabled = busy.kind !== 'idle'
  const otherErrors = Object.entries(errors)
    .filter(([key]) => !FIELD_KEYS.has(key) && !isAliasKey(key))
    .flatMap(([, messages]) => messages)
  const hasDuplicates = duplicates.kind === 'loaded' && (duplicates.sameName.length > 0 || duplicates.sameRecipe.length > 0)

  return (
    <div className={hasDuplicates ? `${styles.layout} ${styles.withDuplicates}` : styles.layout}>
      {hasDuplicates && (
        <CocktailDuplicates
          sameName={duplicates.sameName}
          sameRecipe={duplicates.sameRecipe}
          methodNameOf={(id) => methodNames.get(String(id))}
        />
      )}
      <form className={styles.form} noValidate onSubmit={(event) => void submit(event)}>
        {duplicates.kind === 'failed' && (
          <p className={styles.notice} role="status">
            同じ名前のカクテルを確かめられませんでした。
          </p>
        )}
        <fieldset className={styles.base} aria-describedby={errors.base_category_id?.length ? baseErrorId : undefined}>
          <legend className={styles.legend}>ベース</legend>
          <p className={styles.selected}>
            選んでいるベース: {(values.baseCategoryId === null ? null : pathOf(values.baseCategoryId)) ?? '(未選択)'}
          </p>
          <FieldErrors id={baseErrorId} messages={errors.base_category_id} />
          <CategoryTree
            nodes={nodes}
            label="ベース"
            appearance={appearance}
            selection={{
              mode: 'single',
              selectedId: values.baseCategoryId,
              onSelect: (node) => editIngredient({ kind: 'base', categoryId: node.id }),
              isSelectable,
            }}
          />
        </fieldset>

        <div className={styles.row}>
          <SelectField
            label="氷の有無"
            errors={errors.ice_id}
            value={values.iceId}
            options={toOptions(options.ices)}
            empty="none"
            onChange={(value) => set('iceId', value)}
          />
          <SelectField
            label="グラス"
            errors={errors.glass_dictionary_id}
            value={values.glassId}
            options={toOptions(options.glasses)}
            empty="none"
            onChange={(value) => set('glassId', value)}
          />
        </div>

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

        <ul className={styles.searchLinks}>
          <li>
            <a href={meaningSearchUrl(values.nameEng)} target="_blank" rel="noopener noreferrer">
              意味検索
            </a>
          </li>
          <li>
            <a href={cocktailWordSearchUrl(values.name)} target="_blank" rel="noopener noreferrer">
              カクテル言葉検索
            </a>
          </li>
        </ul>

        <CocktailAliasesField
          aliases={values.aliases}
          errors={errors}
          disabled={disabled}
          onChange={(aliases) => set('aliases', aliases)}
          onEditRow={(index) => clearErrors((key) => key === `aliases.${index}`)}
          onRowsChanged={() => clearErrors(isAliasKey)}
        />

        <div className={styles.row}>
          <SelectField
            label="製法"
            errors={errors.method_id}
            value={values.methodId}
            options={toOptions(options.methods)}
            empty="placeholder"
            onChange={(value) => set('methodId', value)}
          />
          <SelectField
            label="評価"
            errors={errors.rate}
            value={values.rate}
            options={RATE_SELECT}
            empty="none"
            onChange={(value) => set('rate', value)}
          />
          <SelectField
            label="甘さ"
            errors={errors.sweet_degree}
            value={values.sweetDegree}
            options={SWEET_DEGREE_SELECT}
            empty="none"
            onChange={(value) => set('sweetDegree', value)}
          />
          <div className={styles.checkField}>
            <label className={styles.check}>
              <input type="checkbox" checked={values.isMajor} onChange={(event) => set('isMajor', event.target.checked)} />
              メジャー
            </label>
            <FieldErrors messages={errors.is_major} />
          </div>
        </div>

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

        <Field label="補足" errors={errors.note}>
          {(props) => (
            <textarea
              {...props}
              className={styles.textarea}
              rows={4}
              value={values.note}
              onChange={(event) => set('note', event.target.value)}
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
          {preview !== null && <img className={styles.preview} src={preview} alt="カクテルの画像" />}
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

        <IngredientRows
          rows={values.ingredients}
          nodes={nodes}
          appearance={appearance}
          isSelectable={isSelectable}
          pathOf={pathOf}
          errors={ingredientErrors}
          onEdit={editIngredient}
        />

        <div className={styles.actions}>
          <div className={styles.saveButtons}>
            <button
              className={styles.primaryButton}
              type="submit"
              disabled={disabled}
              onClick={() => {
                savesAsDraft.current = true
              }}
            >
              下書きとして保存
            </button>
            <button
              className={styles.primaryButton}
              type="submit"
              disabled={disabled}
              onClick={() => {
                savesAsDraft.current = false
              }}
            >
              公開する
            </button>
          </div>
          {cocktailId !== null && (
            <button className={styles.deleteButton} type="button" disabled={disabled} onClick={() => void remove(cocktailId)}>
              削除
            </button>
          )}
        </div>
        <FieldErrors messages={otherErrors} />
        {failure.kind !== 'none' && (
          <div className={styles.failure} role="alert">
            {failure.kind === 'save' ? <p>{saveFailureMessage(failure.failure)}</p> : <DeleteFailureMessage failure={failure.failure} />}
          </div>
        )}
      </form>
    </div>
  )
}

function saveFailureMessage(failure: SaveFailure): string {
  switch (failure.kind) {
    case 'invalid':
      return '保存できませんでした。入力を確かめてください。'
    case 'http-error':
      return `保存できませんでした(エラー ${failure.status})。時間をおいて、もう一度お試しください。`
    case 'network-error':
      return '保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
  }
}

function DeleteFailureMessage({ failure }: { failure: DeleteFailure }) {
  switch (failure.kind) {
    case 'conflict':
      return (
        <>
          <p>削除できませんでした。</p>
          <ul className={styles.conflictReasons}>
            {failure.reasons.map((reason) => (
              <li key={reason.code}>
                {reason.message}: {reason.referrers.map((referrer) => referrer.name).join('、')}
              </li>
            ))}
          </ul>
        </>
      )
    case 'http-error':
      return <p>削除できませんでした(エラー {failure.status})。時間をおいて、もう一度お試しください。</p>
    case 'network-error':
      return <p>削除できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。</p>
  }
}

/** API の木のノードを、木の部品のノードにする(検索フォームの木は英名を持たない。銘柄のフォームと同じ) */
function toCategoryTreeNode(node: SearchFormNode): CategoryTreeNode {
  return { id: node.id, name: node.name, name_eng: null, is_major: node.is_major, children: node.children.map(toCategoryTreeNode) }
}

