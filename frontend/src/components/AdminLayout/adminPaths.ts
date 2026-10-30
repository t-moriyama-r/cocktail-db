/** 管理画面のメニュー(トップ)の URL */
export const ADMIN_HOME_PATH = '/admin'

/** 管理画面へのログインの画面の URL。ログインの前に開くので、管理画面の枠(AdminLayout)の外に置く */
export const ADMIN_LOGIN_PATH = '/admin/login'

/** ログインの後に戻す URL を持たせる、ログインの画面のクエリの名前 */
const REDIRECT_PARAM = 'redirect'

type Location = { pathname: string; search: string; hash: string }

/**
 * 未ログインで管理画面を開いたときに移す、ログインの画面の URL。開こうとしていた URL(パス・クエリ・ハッシュ)を持たせ、
 * ログインの後にそこへ戻す(#119・docs/decisions/admin-screens.md の 7)。メニューを開こうとしていたときは持たせない
 * (持たせなくても、ログインの後はメニューへ戻る)。
 */
export const adminLoginPath = ({ pathname, search, hash }: Location): string => {
  const target = `${pathname}${search}${hash}`
  if (target === ADMIN_HOME_PATH) {
    return ADMIN_LOGIN_PATH
  }
  return `${ADMIN_LOGIN_PATH}?${new URLSearchParams({ [REDIRECT_PARAM]: target })}`
}

/**
 * ログインの画面のクエリ(`?redirect=…`)から、ログインの後に移す URL を決める。
 *
 * 戻すのは、このサイトの管理画面(/admin 以下)だけにする。クエリは誰でも書き換えられるので、
 * ほかのサイト(`https://…`・`//…`)や公開ページへ移すリンクを作られないようにする。
 * ログインの画面そのものに戻すと、ログインの後にまたログインの画面が出るので、そのときもメニューへ移す。
 */
export const afterAdminLoginPath = (search: string): string => {
  const target = new URLSearchParams(search).get(REDIRECT_PARAM)
  if (target === null || !target.startsWith('/')) {
    return ADMIN_HOME_PATH
  }
  // 仮のオリジンの上で URL として読む。`//…`・`/\…` のようにほかのオリジンになるものはオリジンが変わり、
  // `/admin/../` のような書き方はパスがそろうので、そろえた後のパスで管理画面かどうかを見る
  const base = 'http://localhost'
  const url = new URL(target, base)
  const isAdmin = /^\/admin(?:\/|$)/.test(url.pathname)
  const isLogin = url.pathname.replace(/\/+$/, '') === ADMIN_LOGIN_PATH
  if (url.origin !== base || !isAdmin || isLogin) {
    return ADMIN_HOME_PATH
  }
  return `${url.pathname}${url.search}${url.hash}`
}
