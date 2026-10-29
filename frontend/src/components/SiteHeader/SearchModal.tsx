import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import styles from './SiteHeader.module.css'

type Props = {
  onClose: () => void
  children?: ReactNode
}

/**
 * 条件検索のフォームを出すモーダルの枠(旧 Header/CocktailSearchModal.vue)。右上の「Close」・Esc・外側を押すと閉じる。
 * 中身(条件検索のフォーム)は #307 で置く。開いているあいだは、後ろのページをスクロールさせない。
 */
export default function SearchModal({ onClose, children }: Props) {
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    // 開いたら Close にフォーカスを移し、閉じたら開く前にフォーカスのあったもの(「検索をはじめる」)に戻す
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    closeRef.current?.focus()
    const { overflow } = document.documentElement.style
    document.documentElement.style.overflow = 'hidden'
    return () => {
      document.documentElement.style.overflow = overflow
      previous?.focus()
    }
  }, [])

  useEffect(() => {
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose()
      }
    }
    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [onClose])

  return createPortal(
    <div
      className={styles.modalOverlay}
      onClick={(event) => {
        // 外側(暗くした部分)を押したときだけ閉じる。中を押しても閉じない
        if (event.target === event.currentTarget) {
          onClose()
        }
      }}
    >
      <div role="dialog" aria-modal="true" aria-label="カクテル検索" className={styles.modal}>
        <div className={styles.modalBar}>
          <button type="button" ref={closeRef} className={styles.modalClose} onClick={onClose}>
            Close
          </button>
        </div>
        <div className={styles.modalBody}>{children}</div>
      </div>
    </div>,
    document.body,
  )
}
