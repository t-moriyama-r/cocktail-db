import { useEffect, useRef, type MouseEvent } from 'react'
import { Link } from 'react-router'
import { pinScrollTo } from './pinScrollTo'
import styles from './Top.module.css'

/** トップの条件検索のフォームの置き場所の id。メニューの「・カクテル検索」がここへ飛ぶ(旧と同じ id にし、旧のページ内リンクもそのまま効くようにする) */
export const SEARCH_FORM_ANCHOR = 'cocktailform_anchor'

/** 作者の YouTube のチャンネル(旧 Top/infomationMenu.vue と同じ) */
const VIDEO_LIST_URL = 'https://www.youtube.com/channel/UCJBSxULGc7KiQfjo85qoG7g'

/** 「What is this!?」の「私(ろんぐ)」のリンク先(旧と同じ) */
const AUTHOR_URL = 'http://trc-vlackhistory.net'

/**
 * トップのメニュー(旧 Top/infomationMenu.vue)。文言は旧と同じ。
 * 旧は幅で置き場所を変え、1264px 以上では新着動画の欄の中にしか置いていなかったため、新着動画が無いとメニューも消えた。
 * 新は新着動画の有無によらず、新着動画の下にいつも出す。
 */
export default function InformationMenu() {
  const stopPin = useRef<(() => void) | null>(null)
  // 画面を移ったら、飛んだ先に留めるのをやめる
  useEffect(() => () => stopPin.current?.(), [])

  // 読み込み中に押しても、後から上の欄が伸びた分を追って、フォームを画面の先頭に出す(#646)。
  // ページ内リンクの動き(URL の #cocktailform_anchor・ブラウザのスクロール)はそのまま残す
  const onSearchClick = (event: MouseEvent<HTMLAnchorElement>) => {
    // 新しいタブなどで開くときは、この画面を動かさない
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return
    }
    stopPin.current?.()
    const target = document.getElementById(SEARCH_FORM_ANCHOR)
    stopPin.current = target ? pinScrollTo(target) : null
  }

  return (
    <div className={styles.menu}>
      <section className={styles.menuChild} aria-labelledby="top-menu-title">
        <h2 className={styles.heading} id="top-menu-title">
          Menu
        </h2>
        <div className={styles.menuBlock}>
          <div className={styles.menuLink}>
            ・
            <a href={`#${SEARCH_FORM_ANCHOR}`} onClick={onSearchClick}>
              カクテル検索
            </a>
          </div>
          <div>
            具体的な条件を指定してカクテルが検索できます。
            <br />
            名前から検索する時は上部のフォームよりどうぞ。
          </div>
        </div>
        <div className={styles.menuBlock}>
          <div className={styles.menuLink}>
            ・
            <a href={VIDEO_LIST_URL} target="_blank" rel="noreferrer">
              カクテル動画リスト
            </a>
          </div>
          <div>カクテルのメイキング＆感想動画のリスト(youtubeに飛びます)</div>
        </div>
        <div className={styles.menuBlock}>
          <div className={styles.menuLink}>
            ・<Link to="/dictionary">用語辞典</Link>
          </div>
          <div>素人解説の辞典です。分からない単語があれば。</div>
        </div>
      </section>
      <section className={styles.menuChild} aria-labelledby="top-about-title">
        <h2 className={styles.heading} id="top-about-title">
          What is this!?
        </h2>
        <p className={styles.about}>
          <a href={AUTHOR_URL} target="_blank" rel="noreferrer">
            私(ろんぐ)
          </a>
          が、個人的に作ったカクテルのデータベースです。
          <br />
          <b>「今手元にあるお酒で何が作れるか」</b>
          <b>「聞いたことのないカクテルとの出会い」</b>に重点を置いています。
          <br />
          <br />
          私自身が作って飲んだカクテルに関しては、<s>私が忘れるので</s>評価とコメントを残しています。
          <br />
          <br />
          私がおすすめするカクテルを試してみるも良し！地雷臭のするカクテルに挑戦しても良し！
          <br />
          宅飲みの際の話題のネタにも。カクテルは意外と簡単に作れるものです！ビールだけじゃ勿体無い！
          <br />
          <br />
          さあ、あなたも是非おうちでカクテル、作りませんか！？
          <br />
          まずは下のフォーム、もしくは上の検索ボタンから、知ってるカクテルでも探してみましょう！
        </p>
      </section>
    </div>
  )
}
