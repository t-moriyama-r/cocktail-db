import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'

export default function Top() {
  usePageTitle('トップページ')
  const [message, setMessage] = useState('loading...')

  useEffect(() => {
    api
      .GET('/hello')
      .then(({ data, error, response }) => {
        setMessage(data ? data.message : `error: HTTP ${response.status} ${JSON.stringify(error)}`)
      })
      .catch((err: unknown) => setMessage(`error: ${String(err)}`))
  }, [])

  return (
    <main>
      <h1>{message}</h1>
    </main>
  )
}
