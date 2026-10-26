import type { ReactNode } from 'react'
import { useParams } from 'react-router'
import NotFound from './pages/NotFound'

type Props = {
  element: ReactNode
}

/**
 * URL の :id が数字だけのときだけ element を出し、それ以外は 404 の画面を出す。
 * React Router のパスには旧(vue-router)の `:id(\d+)` のような正規表現の制約を書けないため、
 * 描画のときに確かめる。DB の ID は数字なので、数字でない ID は存在しない URL として扱う。
 */
export default function RequireNumericId({ element }: Props) {
  const { id } = useParams()
  return id !== undefined && NUMERIC_ID.test(id) ? element : <NotFound />
}

const NUMERIC_ID = /^\d+$/
