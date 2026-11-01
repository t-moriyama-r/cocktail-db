import { useId } from 'react'
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

/**
 * 条件検索のフォーム(#307。旧 Forms/Search/Cocktail/form.vue)。見出しは「カクテル検索」。
 * ヘッダーのモーダル(#99)と、トップの下のページ内(#50)の 2 か所に置ける部品にしている。
 *
 * - 項目と既定値は旧と同じ: 検索方法(OR 検索)・かんたん検索(オフ)・製法(すべてチェック)・材料(なし)
 * - 材料が 0 個のあいだは、材料の欄の下に「材料は必須です。」を出し、検索ボタンを押せない(旧は「カテゴリは必須です。」)
 * - 製法は 0 個でも検索できる(docs/decisions/search-screen.md の 6。旧は「製法は必須です。」で押せなかった)
 * - 「リセット」で既定値に戻す
 *
 * 旧の「保存」「読み込み」「リロード」(localStorage)は #104 で足す
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

  return (
    <form
      className={styles.form}
      aria-labelledby={`${id}-title`}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        if (error === null) {
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
            <div className={styles.option}>
              <div className={styles.optionControl}>
                <button
                  type="button"
                  className={styles.smallButton}
                  aria-describedby={`${id}-reset-desc`}
                  onClick={() => onValuesChange(defaultSearchFormValues)}
                >
                  リセット
                </button>
              </div>
              <div id={`${id}-reset-desc`} className={styles.optionDescription}>
                初期状態に戻します
              </div>
            </div>
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

      {/* 旧と同じく、材料の欄の下・検索ボタンの上に出す。モーダルでは検索ボタンと一緒に下端に貼り付け、
          ボタンに隠れないようにする(#576。docs/decisions/search-submit-bar.md) */}
      <div className={inModal ? styles.submitBarFixed : undefined}>
        {error !== null && (
          <p id={errorId} className={styles.error}>
            {error}
          </p>
        )}
        <div className={styles.submitRow}>
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
      </div>
    </form>
  )
}
