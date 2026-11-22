import { Link } from 'react-router'
import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import ApiError from '../../components/ApiView/ApiError'
import Loading from '../../components/ApiView/Loading'
import Description from '../../components/Description/Description'
import YouTubeEmbed from '../../components/YouTubeEmbed/YouTubeEmbed'
import styles from './Top.module.css'

const fetchLatestVideo = () => api.GET('/cocktails/latest-video')

/**
 * トップの新着動画(旧 components/index.vue の youtube_block)。動画の公開がいちばん新しいカクテルの動画・名前・ベース・説明文を出す。
 *
 * - 動画のあるカクテルが無い(API が 404)ときは、欄ごと出さない(旧と同じ)
 * - 埋め込みは開始位置を使わない(旧と同じ。#302)
 * - 応答を待っているあいだは読み込み中の表示、失敗したらエラーの表示と「もう一度読み込む」を出す(#98)
 */
export default function LatestVideo() {
  const { state, retry } = useApi(fetchLatestVideo)

  switch (state.kind) {
    case 'loading':
      return <Loading />
    case 'not-found':
      return null
    case 'http-error':
    case 'network-error':
      return <ApiError error={state} onRetry={retry} />
    case 'success':
      break
  }

  const cocktail = state.data.data
  return (
    <section className={styles.video} aria-label="新作動画">
      <div className={styles.videoPlayer}>
        <YouTubeEmbed videoId={cocktail.youtube} />
      </div>
      <div className={styles.videoDetail}>
        {/* 英名が無いときも、旧と同じく括弧は出す */}
        <h2 className={styles.heading}>
          新作動画：<Link to={`/detail/cocktail/${cocktail.id}`}>{`${cocktail.name}(${cocktail.name_eng ?? ''})`}</Link>
        </h2>
        <div className={styles.videoText}>
          <p className={styles.videoBase}>
            ベース：
            <Link to={`/category/${cocktail.base.id}`}>{`${cocktail.base.name}(${cocktail.base.name_eng})`}</Link>
          </p>
          <Description segments={cocktail.description} />
        </div>
      </div>
    </section>
  )
}
