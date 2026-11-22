import styles from './SquareDot.module.css'

/**
 * 詳細の画面の欄の見出しの前に置く、二重線の小さな四角(旧 Detail/detail.scss の square_dot)。
 * 見出しの文字で意味が伝わる飾りなので、読み上げない。色は見出しの文字の色(currentColor)を使う。
 */
export default function SquareDot() {
  return <span className={styles.squareDot} aria-hidden="true" />
}
