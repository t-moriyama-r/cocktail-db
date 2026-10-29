import { Route, Routes } from 'react-router'
import RequireNumericId from './RequireNumericId'
import SiteLayout from './components/SiteLayout/SiteLayout'
import BrandDetail from './pages/BrandDetail'
import CategoryDetail from './pages/CategoryDetail'
import CocktailDetail from './pages/CocktailDetail'
import CocktailSearchResult from './pages/CocktailSearchResult'
import DictionaryDetail from './pages/DictionaryDetail'
import DictionaryIndex from './pages/DictionaryIndex'
import HelpMe from './pages/HelpMe'
import NameSearchResult from './pages/NameSearchResult'
import NotFound from './pages/NotFound'
import Top from './pages/Top'

/**
 * 公開ページの URL と画面の対応。URL は旧サイト(旧 resources/js/routes.js)に合わせ、
 * 外から貼られたリンク・ブックマークがそのまま開けるようにしている。
 *
 * カテゴリ・ミドルの詳細(旧 /detail/category/:id・/detail/middle/:id)は /category/:id にまとめ、
 * 旧 URL はサーバーが 301 で転送するので画面のルートには置かない。
 * 管理画面(/admin 以下)は別の画面として作るので、ここには置かない。
 * 公開ページは、どれも共通の枠(ヘッダー・フッター。SiteLayout)の中に出す。404 の画面も同じ。
 */
export default function App() {
  return (
    <Routes>
      <Route element={<SiteLayout />}>
        <Route path="/" element={<Top />} />
        <Route path="/index" element={<Top />} />
        <Route path="/helpme" element={<HelpMe />} />
        <Route path="/result/FromNameResult/:word" element={<NameSearchResult />} />
        {/* 旧で URL の語で検索し直すための別名。画面は同じ */}
        <Route path="/result/FromNameResultEx/:word" element={<NameSearchResult />} />
        <Route path="/result/Cocktails" element={<CocktailSearchResult />} />
        <Route path="/category/:id" element={<RequireNumericId element={<CategoryDetail />} />} />
        <Route path="/detail/brand/:id" element={<RequireNumericId element={<BrandDetail />} />} />
        <Route path="/detail/cocktail/:id" element={<RequireNumericId element={<CocktailDetail />} />} />
        <Route path="/dictionary" element={<DictionaryIndex />} />
        {/* 旧でも用語の ID に数字の制限は無かった */}
        <Route path="/dictionary/:id" element={<DictionaryDetail />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
