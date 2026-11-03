import { api } from '../../api/client'

/**
 * ログインしている管理者を取る。ログインしていなければ 401。
 * 管理画面の枠(AdminLayout)と、ログインの画面(ログイン済みなら戻す)が使う。
 * モジュールの定数にして、useApi に描画のたびに別の関数を渡さないようにする。
 */
export const fetchCurrentAdmin = () => api.GET('/admin/me')
