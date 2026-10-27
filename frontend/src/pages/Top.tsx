import { useEffect, useState } from 'react'
import { api } from '../api/client'

export default function Top() {
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
