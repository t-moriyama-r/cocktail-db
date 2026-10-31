import { Route, Routes } from 'react-router'
import RequireNumericId from './RequireNumericId'
import AdminLayout from './components/AdminLayout/AdminLayout'
import SiteLayout from './components/SiteLayout/SiteLayout'
import AdminHome from './pages/AdminHome'
import AdminLogin from './pages/AdminLogin'
import AdminPasskeys from './pages/AdminPasskeys'
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
 * 公開ページは、どれも共通の枠(ヘッダー・フッター。SiteLayout)の中に出す。404 の画面も同じ。
 *
 * 管理画面(/admin 以下)は、公開ページの枠ではなく管理画面の枠(題とメニュー。AdminLayout)の中に出す(#119)。
 * 枠はログインしているかを確かめ、未ログインならログインの画面(/admin/login)へ移す。
 * ログインの画面はログインの前に開くので、枠の外に置く。
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
        {/* 旧は用語の ID に数字の制限が無く、数字でない ID では API が 422 で何も出さなかった。新は無い ID と同じく 404 の画面(#56) */}
        <Route path="/dictionary/:id" element={<RequireNumericId element={<DictionaryDetail />} />} />
        <Route path="*" element={<NotFound />} />
      </Route>
      <Route path="/admin/login" element={<AdminLogin />} />
      <Route path="/admin" element={<AdminLayout />}>
        <Route index element={<AdminHome />} />
        <Route path="passkeys" element={<AdminPasskeys />} />
        {/* まだ無い管理画面の URL も、ログインを確かめてから(未ログインならログインへ移してから)404 の画面を出す */}
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
