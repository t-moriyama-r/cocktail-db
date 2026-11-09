import { useId } from 'react'
import type { CocktailFieldErrors } from './cocktailApi'
import { FieldErrors, TextField } from './CocktailFormFields'
import type { CocktailAliasRow } from './cocktailFormValues'
import styles from './CocktailForm.module.css'

type Props = {
  aliases: readonly CocktailAliasRow[]
  /** 保存の 422 の項目ごとのメッセージ。`aliases`(別名の全体)と `aliases.<番号>`(その行)を読む。ほかの項目は読まない */
  errors: CocktailFieldErrors
  /** 保存を待つあいだは、行を足す・消すをさせない(返ってきたメッセージの行の番号がずれないように) */
  disabled: boolean
  onChange: (aliases: readonly CocktailAliasRow[]) => void
  /** 行の欄を直したとき(その行のメッセージを外す)。番号は 0 から */
  onEditRow: (index: number) => void
  /** 行を足した・消したとき(行の番号がずれるので、別名のメッセージをすべて外す) */
  onRowsChanged: () => void
}

/**
 * カクテルの別名の欄(#83。旧に無い)。別名ごとに 1 行の欄と「削除」を並べ、「別名を足す」で空の行を足す。
 *
 * 検証は API に任せる(docs/decisions/admin-cocktails.md の 7)。検索用の名前が同じ別名・カクテルの名前と同じ別名・空の行は
 * 422 で返り、その行の欄の下に出す。ほかのカクテルの名前・別名と同じ別名は、保存したうえで一覧へ戻った先に出す(#293 の warnings)。
 */
export default function CocktailAliasesField({ aliases, errors, disabled, onChange, onEditRow, onRowsChanged }: Props) {
  const headingId = useId()
  // 行に当てられないメッセージ(別名の全体の `aliases` と、今は無い行の番号のもの)は、別名の欄の下にまとめて出す
  const rowKeys = new Set(aliases.map((_, index) => `aliases.${index}`))
  const sectionErrors = Object.entries(errors)
    .filter(([key]) => key === 'aliases' || (key.startsWith('aliases.') && !rowKeys.has(key)))
    .flatMap(([, messages]) => messages)

  const edit = (index: number, name: string) => {
    onChange(aliases.map((alias, i) => (i === index ? { ...alias, name } : alias)))
    onEditRow(index)
  }
  const remove = (index: number) => {
    onChange(aliases.filter((_, i) => i !== index))
    onRowsChanged()
  }
  const add = () => {
    const key = aliases.reduce((max, alias) => Math.max(max, alias.key), -1) + 1
    onChange([...aliases, { key, name: '' }])
    onRowsChanged()
  }

  return (
    <section className={styles.aliases} aria-labelledby={headingId}>
      <h2 id={headingId} className={styles.aliasesHeading}>
        別名
      </h2>
      {aliases.length === 0 ? (
        <p className={styles.aliasesNote}>別名はありません。</p>
      ) : (
        <ul className={styles.aliasList}>
          {aliases.map((alias, index) => {
            const label = `別名 ${index + 1}`
            return (
              <li key={alias.key} className={styles.aliasRow}>
                <TextField label={label} errors={errors[`aliases.${index}`]} value={alias.name} onChange={(name) => edit(index, name)} />
                <button
                  className={styles.secondaryButton}
                  type="button"
                  aria-label={`${label} を削除`}
                  disabled={disabled}
                  onClick={() => remove(index)}
                >
                  削除
                </button>
              </li>
            )
          })}
        </ul>
      )}
      <FieldErrors messages={sectionErrors} />
      <div>
        <button className={styles.secondaryButton} type="button" disabled={disabled} onClick={add}>
          別名を足す
        </button>
      </div>
    </section>
  )
}
