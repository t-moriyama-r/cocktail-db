import { Fragment } from 'react'
import { Link } from 'react-router'
import type { components } from '../../api/schema'
import styles from './Description.module.css'

/**
 * 説明文の 1 区切り(docs/decisions/description-segments.md)。文字の区切りか、ノード・カクテル・銘柄・用語へのリンクの区切り。
 * 説明文を返す API(カクテル・銘柄・カテゴリ・用語・新着動画)はどれも同じ形で返すので、カクテルの詳細の型から取る
 */
export type DescriptionSegment = NonNullable<components['schemas']['CocktailDetailResource']['description']>[number]

type LinkSegment = Exclude<DescriptionSegment, { type: 'text' }>

/** リンクの区切りの種類ごとのリンク先(App.tsx のルートと同じ URL) */
const LINK_PATHS: Record<LinkSegment['type'], (id: number) => string> = {
  node: (id) => `/category/${id}`,
  cocktail: (id) => `/detail/cocktail/${id}`,
  brand: (id) => `/detail/brand/${id}`,
  dictionary: (id) => `/dictionary/${id}`,
}

type Props = {
  /** API が返す説明文(description・note)。null は説明文が無い */
  segments: readonly DescriptionSegment[] | null | undefined
}

/**
 * 詳細の画面(カテゴリ・銘柄・カクテル・用語)とトップの新着動画に出す説明文(旧 Detail/component/description.vue)。
 * 文字の区切りは HTML として描かず(旧は v-html だった)、改行だけを改行として出す。
 * リンク先に動画があるリンクは、旧と同じく色を変える。
 */
export default function Description({ segments }: Props) {
  if (!segments || segments.length === 0) {
    return null
  }

  return (
    <div className={styles.description}>
      {segments.map((segment, index) =>
        segment.type === 'text' ? (
          <TextSegment key={index} text={segment.text} />
        ) : (
          <Link
            key={index}
            to={LINK_PATHS[segment.type](segment.id)}
            className={segment.has_youtube ? styles.hasMovie : undefined}
          >
            {segment.text}
          </Link>
        ),
      )}
    </div>
  )
}

/** 旧の BE は nl2br で改行に <br /> を足していた。nl2br と同じく \r\n・\n\r・\r・\n を 1 つの改行として扱う */
const LINE_BREAK = /\r\n|\n\r|\r|\n/

function TextSegment({ text }: { text: string }) {
  return text.split(LINE_BREAK).map((line, index) => (
    <Fragment key={index}>
      {index > 0 && <br />}
      {line}
    </Fragment>
  ))
}
