import styles from './CreditedImage.module.css'

/**
 * 詳細の画面(カクテル・銘柄・用語など)に出す画像と、その下の出典。
 * 画像は外部のサイトから借りたものがあり、出典(と、あれば出典のページへのリンク)を添える決まりで載せている。
 * 画像の無い詳細もあるため、画像が無ければ出典があっても何も出さない。
 */
type Props = {
  src: string | null | undefined
  alt: string
  /** 出典のページの URL */
  creditUrl: string | null | undefined
  /** 出典として出す文字 */
  creditText: string | null | undefined
}

export default function CreditedImage({ src, alt, creditUrl, creditText }: Props) {
  if (!src) {
    return null
  }

  return (
    <figure className={styles.figure}>
      <img className={styles.image} src={src} alt={alt} />
      <figcaption className={styles.credit}>
        <Credit url={creditUrl} text={creditText} />
      </figcaption>
    </figure>
  )
}

/**
 * 出典の文字。旧は URL があれば文字が空でもリンクを置いていた(見た目には何も出ない)ので、それに合わせる。
 */
function Credit({ url, text }: { url: string | null | undefined; text: string | null | undefined }) {
  if (url) {
    return <a href={url}>{text}</a>
  }
  return text || null
}
