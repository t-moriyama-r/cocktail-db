import type { CategoryTreeCheckState } from './CategoryTree.types'

type Props = {
  label: string
  state: CategoryTreeCheckState
  /** 押した後にチェックを入れるなら true。「一部」を押したときもチェックを入れる */
  onChange: (checked: boolean) => void
  className?: string
}

/**
 * 「チェック・一部・なし」の 3 つの状態を出すチェックボックス。
 * 「一部」(indeterminate)は HTML の属性では指定できず、DOM の値でしか持てないため、ref で設定する。
 * effect を使わず、描くたびに呼ばれる ref の関数で設定する(描いたその場で反映され、effect の分の処理も増えない)。
 */
export default function TriStateCheckbox({ label, state, onChange, className }: Props) {
  const isPartial = state === 'partial'

  return (
    <input
      ref={(input) => {
        if (input !== null) input.indeterminate = isPartial
      }}
      type="checkbox"
      className={className}
      aria-label={label}
      checked={state === 'checked'}
      onChange={() => onChange(state !== 'checked')}
    />
  )
}
