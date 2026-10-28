import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'

const fetchHello = () => api.GET('/hello')

export default function Top() {
  const { state, retry } = useApi(fetchHello)

  return (
    <ApiView state={state} onRetry={retry}>
      {(data) => (
        <main>
          <h1>{data.message}</h1>
        </main>
      )}
    </ApiView>
  )
}
