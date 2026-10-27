import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import DesignProposals from './DesignProposals'
import './tokens.css'

// 見た目の案の試作の入口(frontend/design.html から読む)。vite.config.ts の build.rollupOptions.input に無いので本番のビルドには入らない
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <DesignProposals search={window.location.search} />
  </StrictMode>,
)
