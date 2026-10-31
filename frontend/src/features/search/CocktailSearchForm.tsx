import { useId, useMemo, useState } from 'react'
import type { components } from '../../api/schema'
import MethodIcon from '../../components/CocktailTable/MethodIcon'
import IngredientTreeField from './IngredientTreeField'
import {
  defaultSearchFormValues,
  ingredientError,
  toggleMethod,
  toSearchConditions,
  type SearchConditions,
  type SearchFormValues,
  type SearchMode,
} from './searchForm'
import { buildSelectionTree } from './selection'
import { sortSearchFormTree } from './sortSearchFormTree'
import { hasSavedSearch, loadSearch, saveSearch, type SearchStorageSlot } from './searchStorage'
import styles from './CocktailSearchForm.module.css'

type SearchFormNode = components['schemas']['SearchFormNodeResource']
type SearchFormMethod = components['schemas']['SearchFormMethodResource']

export type CocktailSearchFormProps = {
  /** GET /api/search-form の categories */
  categories: readonly SearchFormNode[]
  /** GET /api/search-form の methods */
  methods: readonly SearchFormMethod[]
  /** フォームの値。状態は持たず、外から受け取る(モーダルを閉じて開き直しても、条件を残せるように) */
  values: SearchFormValues
  onValuesChange: (next: SearchFormValues) => void
  /** 検索ボタンを押したときに呼ぶ。材料が 0 個のあいだは押せないので呼ばない */
  onSubmit: (conditions: SearchConditions) => void
  /** 検索中なら true。検索ボタンに読み込み中の表示を出す(旧と同じく、押せなくはしない) */
  searching?: boolean
  /** モーダルの中に置くなら true。検索ボタンを左下に固定する(旧の sendBtnFixed) */
  inModal?: boolean
  /** 材料の名前を押して詳細へ移った後に呼ぶ(モーダルを閉じるのに使う) */
  onNavigate?: () => void
}

/** 検索方法の選択肢と説明(旧 form.vue と同じ文言) */
const MODES: readonly { value: SearchMode; label: string; description: string }[] = [
  { value: 'or', label: 'OR検索', description: 'チェックした「いずれか」が含まれているカクテルを検索' },
  { value: 'and', label: 'AND検索', description: 'チェックした「すべて」が含まれているカクテルを検索' },
  {
    value: 'having',
    label: 'あるもの検索',
    description: '「チェックが入ったものだけ」で作れるカクテルを検索("その他"は省略可能なので無視されます)',
  },
]

const EASY_DESCRIPTION = '材料が少なく(3つ以下)簡単に作れるレシピのみに絞ります'

/** 「読み込み」の欄のボタンと説明(旧 form.vue と同じ文言・同じ並び)。リセットは保存先を持たない */
const STORAGE_ACTIONS: readonly {
  key: string
  label: string
  description: string
  /** 保存するなら save、読むなら load。リセットは null */
  action: { kind: 'save' | 'load'; slot: SearchStorageSlot } | null
}[] = [
  {
    key: 'save',
    label: '保存',
    description: '現在のチェック状況を保存(持っているお酒の状況を保存するのに使います)',
    action: { kind: 'save', slot: 'user' },
  },
  { key: 'load', label: '読み込み', description: '保存したデータを読み込みます。', action: { kind: 'load', slot: 'user' } },
  { key: 'reload', label: 'リロード', description: '前回の検索条件を読み込みます。', action: { kind: 'load', slot: 'instant' } },
  { key: 'reset', label: 'リセット', description: '初期状態に戻します', action: null },
]

/** 保存先ごとに、読める保存があるか */
const readSavedSlots = (): Record<SearchStorageSlot, boolean> => ({
  instant: hasSavedSearch('instant'),
  user: hasSavedSearch('user'),
})

/**
 * 条件検索のフォーム(#307。旧 Forms/Search/Cocktail/form.vue)。見出しは「カクテル検索」。
 * ヘッダーのモーダル(#99)と、トップの下のページ内(#50)の 2 か所に置ける部品にしている。
 *
 * - 項目と既定値は旧と同じ: 検索方法(OR 検索)・かんたん検索(オフ)・製法(すべてチェック)・材料(なし)
 * - 材料が 0 個のあいだは、材料の欄の下に「材料は必須です。」を出し、検索ボタンを押せない(旧は「カテゴリは必須です。」)
 * - 製法は 0 個でも検索できる(docs/decisions/search-screen.md の 6。旧は「製法は必須です。」で押せなかった)
 * - 「リセット」で既定値に戻す
 * - 「保存」「読み込み」「リロード」(#104。旧 localStorageHelper.js。searchStorage.ts)。「保存」は今の値を、「検索」は押した条件を
 *   保存する。「読み込み」「リロード」は、保存が無ければ押せない。開いたときには読まない(docs/decisions/search-screen.md の 4)
 */
export default function CocktailSearchForm({
  categories,
  methods,
  values,
  onValuesChange,
  onSubmit,
  searching = false,
  inModal = false,
  onNavigate,
}: CocktailSearchFormProps) {
  const id = useId()
  const error = ingredientError(values)
  const errorId = `${id}-ingredient-error`
  // 保存・読み込みの木は、材料の欄と同じ並びで作る(保存する ID の並びを URL と同じにするため)
  const tree = useMemo(() => buildSelectionTree(sortSearchFormTree(categories)), [categories])
  // 開いたときに 1 度読み、保存・検索の後に読み直す(保存できたかは localStorage を読み直して決める)
  const [savedSlots, setSavedSlots] = useState(readSavedSlots)

  return (
    <form
      className={styles.form}
      aria-labelledby={`${id}-title`}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        if (error === null) {
          // 旧と同じく、検索の成否によらず押した時点で「リロード」用に保存する
          saveSearch('instant', tree, methods, values)
          setSavedSlots(readSavedSlots())
          onSubmit(toSearchConditions(methods, values))
        }
      }}
    >
      <h2 id={`${id}-title`} className={styles.title}>
        カクテル検索
      </h2>

      <div className={styles.topRows}>
        <div className={styles.row} role="group" aria-labelledby={`${id}-load`}>
          <div id={`${id}-load`} className={styles.rowLabel}>
            読み込み
          </div>
          <div className={styles.rowBody}>
            {STORAGE_ACTIONS.map(({ key, label, description, action }) => (
              <div key={key} className={styles.option}>
                <div className={styles.optionControl}>
                  <button
                    type="button"
                    className={styles.smallButton}
                    aria-describedby={`${id}-${key}-desc`}
                    disabled={action?.kind === 'load' && !savedSlots[action.slot]}
                    onClick={() => {
                      if (action === null) {
                        onValuesChange(defaultSearchFormValues)
                      } else if (action.kind === 'save') {
                        saveSearch(action.slot, tree, methods, values)
                        setSavedSlots(readSavedSlots())
                      } else {
                        const loaded = loadSearch(action.slot, tree, methods)
                        if (loaded !== null) onValuesChange(loaded)
                      }
                    }}
                  >
                    {label}
                  </button>
                </div>
                <div id={`${id}-${key}-desc`} className={styles.optionDescription}>
                  {description}
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className={styles.row} role="group" aria-labelledby={`${id}-mode`}>
          <div id={`${id}-mode`} className={styles.rowLabel}>
            検索方法
          </div>
          <div className={styles.rowBody}>
            <div role="radiogroup" aria-labelledby={`${id}-mode`}>
              {MODES.map((mode) => (
                <div key={mode.value} className={styles.option}>
                  <label className={styles.optionControl}>
                    <input
                      type="radio"
                      name={`${id}-mode`}
                      value={mode.value}
                      checked={values.mode === mode.value}
                      aria-describedby={`${id}-mode-${mode.value}-desc`}
                      onChange={() => onValuesChange({ ...values, mode: mode.value })}
                    />
                    {mode.label}
                  </label>
                  <div id={`${id}-mode-${mode.value}-desc`} className={styles.optionDescription}>
                    {mode.description}
                  </div>
                </div>
              ))}
            </div>
            <div className={styles.option}>
              <label className={styles.optionControl}>
                <input
                  type="checkbox"
                  checked={values.isEasy}
                  aria-describedby={`${id}-easy-desc`}
                  onChange={(event) => onValuesChange({ ...values, isEasy: event.target.checked })}
                />
                かんたん検索
              </label>
              <div id={`${id}-easy-desc`} className={styles.optionDescription}>
                {EASY_DESCRIPTION}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className={styles.row} role="group" aria-labelledby={`${id}-method`}>
        <div id={`${id}-method`} className={styles.rowLabel}>
          製法
        </div>
        <div className={styles.rowBody}>
          {methods.map((method) => (
            <label key={method.id} className={styles.method}>
              <input
                type="checkbox"
                checked={!values.uncheckedMethodIds.has(method.id)}
                onChange={(event) => onValuesChange(toggleMethod(values, method.id, event.target.checked))}
              />
              {/* 名前を文字で並べて出すので、アイコンは読み上げない */}
              <span className={styles.methodIcon} aria-hidden="true">
                <MethodIcon method={method} />
              </span>
              {method.name}
            </label>
          ))}
        </div>
      </div>

      <IngredientTreeField
        categories={categories}
        selection={values.selection}
        onSelectionChange={(selection) => onValuesChange({ ...values, selection })}
        onNavigate={onNavigate}
      />

      {/* 旧と同じく、材料の欄の下に出す */}
      {error !== null && (
        <p id={errorId} className={styles.error}>
          {error}
        </p>
      )}

      <div className={inModal ? `${styles.submitBar} ${styles.submitBarFixed}` : styles.submitBar}>
        <button
          type="submit"
          className={styles.submit}
          disabled={error !== null}
          aria-busy={searching}
          aria-describedby={error !== null ? errorId : undefined}
        >
          {searching ? (
            <>
              <span className={styles.spinner} aria-hidden="true" />
              <span className={styles.visuallyHidden}>検索中</span>
            </>
          ) : (
            '検索'
          )}
        </button>
      </div>
    </form>
  )
}
