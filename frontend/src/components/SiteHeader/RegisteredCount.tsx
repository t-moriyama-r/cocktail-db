import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router'
import { toApiResult } from '../../api/apiResult'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'
import { useApi } from '../../api/useApi'
import { brandPath, categoryPath, cocktailPath } from '../../utils/detailPaths'
import styles from './SiteHeader.module.css'

type RandomWalk = paths['/random-walk']['get']['responses'][200]['content']['application/json']

const fetchCount = () => api.GET('/cocktails/count')

/** ランダムウォークの行き先の画面(カクテル・ノード・銘柄の詳細) */
const randomWalkPath = ({ type, id }: RandomWalk) => {
  switch (type) {
    case 'cocktail':
      return cocktailPath(id)
    case 'category':
      return categoryPath(id)
    case 'brand':
      return brandPath(id)
  }
}

/**
 * ヘッダーの右端の「現在の登録数：〈件数〉」(旧 Header/header.vue)。件数は公開しているカクテルの数(#39)。
 * リンク先はランダムウォーク(#45)の行き先で、旧と同じく画面を移るたびに取り直す。
 * 件数が取れていないときは出さない。行き先がまだ取れていないときは、リンクにせず件数だけを出す。
 */
export default function RegisteredCount() {
  const { state } = useApi(fetchCount)
  const destination = useRandomWalkDestination()

  if (state.kind !== 'success') {
    return null
  }
  const label = `現在の登録数：${state.data.count}`
  return (
    <div className={styles.count}>{destination === null ? label : <Link to={destination}>{label}</Link>}</div>
  )
}

/**
 * 画面を移るたびにランダムウォークの行き先を取り直す。取り直しているあいだと失敗したときは、前の行き先のままにする
 * (リンクが一瞬消えないようにするため)。
 */
function useRandomWalkDestination() {
  const { pathname, search } = useLocation()
  const [destination, setDestination] = useState<string | null>(null)

  useEffect(() => {
    let current = true
    void toApiResult(() => api.GET('/random-walk')).then((result) => {
      if (current && result.kind === 'success') {
        setDestination(randomWalkPath(result.data))
      }
    })
    return () => {
      current = false
    }
  }, [pathname, search])

  return destination
}
