import { Link } from 'react-router'

type Props = {
  /** 開く編集画面の URL */
  to: string
  name: string
  /** 英名。無ければ括弧の中を空にする */
  nameEng: string | null
}

/**
 * 管理画面の一覧の 1 行の「名前(英名)」のリンク(旧 `ListComponent.vue` の `{{c.name}}({{c.name_eng}})` と同じ書き方)。
 * 銘柄の所属などを後ろに足すときは、一覧の renderItem でこのリンクの後ろに並べる
 */
export default function AdminListLink({ to, name, nameEng }: Props) {
  return <Link to={to}>{`${name}(${nameEng ?? ''})`}</Link>
}
