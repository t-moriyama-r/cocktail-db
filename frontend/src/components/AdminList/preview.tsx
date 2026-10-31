import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import AdminListPreview from './AdminListPreview'
import '../../styles/tokens.css'
import '../../styles/base.css'

// 部品を画面で確かめる入口(開発サーバーで src/components/AdminList/preview.html を開く)。
// vite.config.ts の build.rollupOptions.input に無いので、本番のビルドには入らない
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AdminListPreview />
  </StrictMode>,
)
