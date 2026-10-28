import styles from './YouTubeEmbed.module.css'

/**
 * 詳細の画面(カクテル・銘柄・カテゴリなど)に出す YouTube の埋め込み。幅いっぱいに 16:9 で出す。
 * 動画が登録されていない詳細もあるため、ID が無ければ何も出さない。
 */
type Props = {
  videoId: string | null | undefined
  /** 再生を始める位置(秒) */
  startSeconds?: number | null
}

export default function YouTubeEmbed({ videoId, startSeconds }: Props) {
  if (!videoId) {
    return null
  }

  return (
    <div className={styles.frame}>
      <iframe
        className={styles.player}
        src={embedUrl(videoId, startSeconds)}
        title="YouTube の動画"
        allow="autoplay; encrypted-media"
        allowFullScreen
      />
    </div>
  )
}

/**
 * 埋め込みの URL。旧は開始位置が 0 秒のとき start を付けず、動画の頭から再生していたので、それに合わせる。
 */
function embedUrl(videoId: string, startSeconds: number | null | undefined): string {
  const base = `https://www.youtube.com/embed/${videoId}`
  return startSeconds && startSeconds > 0 ? `${base}?start=${startSeconds}` : base
}
