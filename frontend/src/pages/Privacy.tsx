import { usePageTitle } from '../components/SiteLayout/usePageTitle'

/** Google の公開の案内(Google のサービスを使うサイトから集めた情報の扱い・プライバシーポリシー・オプトアウトのアドオン) */
const GOOGLE_PARTNER_SITES_URL = 'https://policies.google.com/technologies/partner-sites?hl=ja'
const GOOGLE_PRIVACY_URL = 'https://policies.google.com/privacy?hl=ja'
const GA_OPT_OUT_URL = 'https://tools.google.com/dlpage/gaoptout?hl=ja'

/**
 * プライバシーポリシー(/privacy。#59。旧に無い)。アクセス解析(GA4)を使っていることと、集める情報・止め方を書く。
 * Cookie の同意のバナーは出さず、このページに書く(#59 のオーナーの決定)。フッター(SiteFooter)からリンクする。
 * 外のサイトへのリンクは、フッターと同じく新しいタブで開く。
 */
export default function Privacy() {
  usePageTitle('プライバシーポリシー')
  return (
    <main>
      <h1>プライバシーポリシー</h1>
      <p>宅飲みカクテルデータベース(以下「このサイト」)で使っているアクセス解析のツールと、このサイトを見た人の情報の扱いについて書きます。</p>

      <h2>アクセス解析のツール</h2>
      <p>
        このサイトは、Google LLC が提供するアクセス解析のツール「Google アナリティクス(GA4)」を使っています。どのページがどれだけ見られているかを知り、サイトをよくするためです。
      </p>
      <p>
        Google アナリティクスは Cookie を使い、このサイトを見た人のアクセスの情報(見たページ・見た日時・ブラウザや端末の種類・おおよその地域など)を集めます。集める情報に、名前やメールアドレスなど、個人を特定できる情報は入りません。集めた情報は、Google のプライバシーポリシーに基づいて Google が管理します。
      </p>
      <ul>
        <li>
          <a href={GOOGLE_PARTNER_SITES_URL} target="_blank" rel="noopener noreferrer">
            Google のサービスを使うサイトやアプリから集めた情報の、Google による使い方
          </a>
        </li>
        <li>
          <a href={GOOGLE_PRIVACY_URL} target="_blank" rel="noopener noreferrer">
            Google のプライバシーポリシー
          </a>
        </li>
      </ul>

      <h2>情報を送らないようにするには</h2>
      <p>
        ブラウザの設定で Cookie を無効にするか、Google が提供する「
        <a href={GA_OPT_OUT_URL} target="_blank" rel="noopener noreferrer">
          Google アナリティクス オプトアウト アドオン
        </a>
        」を使うと、Google アナリティクスに情報を送らないようにできます。
      </p>

      <h2>このページの変更</h2>
      <p>このページの内容は、予告なく変えることがあります。変えた内容は、このページに載せたときから効力を持ちます。</p>
    </main>
  )
}
