import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// パスキーの管理の画面(#313)。Chromium の仮想の認証器(CDP の WebAuthn)で、本物の BE とブラウザの WebAuthn を通す。
//
// パスキーは RP ID(ドメイン)に結び付き、BE は RP ID と受け付けるオリジンを APP_URL から取る(docs/decisions/passkey.md)。
// RP ID に IP アドレスは使えないので、passkey-login.spec.ts と同じく、APP_URL(.env.example・CI は http://localhost:8000)と
// 同じ localhost で開く。テストは同じ管理者(E2eSeeder)にパスキーを足していくので、名前はテストごとに変え、名前で行を探す。

const LOGGED_OUT_TEXT = '管理画面を使うには、ログインが必要です。'

/** APP_URL と同じオリジン。baseURL のホストだけを localhost にする */
const passkeyOrigin = (baseURL: string): string => {
  const url = new URL(baseURL)
  url.hostname = 'localhost'
  return url.origin
}

/** E2eSeeder の管理者の非常用のログイン URL(#115)を、origin で開く形で出す(admin-layout.spec.ts と同じ) */
function issueLoginLink(origin: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: origin },
    encoding: 'utf8',
  }).trim()
}

/** このページに、パスキーを覚える仮想の認証器(端末に内蔵の認証器・本人確認は通る)をつなぐ */
async function addVirtualAuthenticator(page: Page) {
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  })
}

/** 非常用のログイン URL でログインし、メニューの「パスキー」から、パスキーの管理の画面を開く */
async function openPasskeys(page: Page, origin: string) {
  await page.goto(issueLoginLink(origin))
  const menu = page.getByRole('navigation', { name: '管理画面のメニュー' })
  await menu.getByRole('link', { name: 'パスキー' }).click()
  await expect(page).toHaveURL(`${origin}/admin/passkeys`)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('パスキー')
}

/** 画面のフォームで、名前を付けてパスキーを登録する */
async function registerOnScreen(page: Page, name: string) {
  await page.getByLabel('端末の名前').fill(name)
  await page.getByRole('button', { name: 'パスキーを登録' }).click()
  await expect(page.getByText(`「${name}」を登録しました。`)).toBeVisible()
}

/** 一覧の、その名前の行 */
const row = (page: Page, name: string) =>
  page.getByRole('list', { name: '登録したパスキー' }).getByRole('listitem', { name, exact: true })

/** 今日の日付(日本の日付。画面と同じ形) */
const today = () =>
  new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(
    new Date(),
  )

async function logout(page: Page) {
  await page.getByRole('button', { name: 'ログアウト' }).click()
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
}

test('TC-313-21: 画面で名前を付けて登録したパスキーが、登録日と「まだ使っていません」で一覧に出る', async ({ page, baseURL }) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  await openPasskeys(page, origin)

  await registerOnScreen(page, 'TC-313-21 の端末')

  const item = row(page, 'TC-313-21 の端末')
  await expect(item).toBeVisible()
  await expect(item.getByRole('definition')).toHaveText([today(), 'まだ使っていません'])
  await expect(page.getByLabel('端末の名前')).toHaveValue('')
})

test('TC-313-22: 登録したパスキーでログインすると、一覧の最後に使った日が今日になる', async ({ page, baseURL }) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  await openPasskeys(page, origin)
  await registerOnScreen(page, 'TC-313-22 の端末')
  await logout(page)

  await page.getByRole('button', { name: 'パスキーでログイン' }).click()
  await expect(page.getByText('E2E の管理者 でログイン中')).toBeVisible()
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: 'パスキー' }).click()

  await expect(row(page, 'TC-313-22 の端末').getByRole('definition')).toHaveText([today(), today()])
})

test('TC-313-23: 一覧から確かめて消したパスキーは一覧から消え、そのパスキーではログインできない', async ({ page, baseURL }) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  await openPasskeys(page, origin)
  await registerOnScreen(page, 'TC-313-23 の端末')

  await row(page, 'TC-313-23 の端末').getByRole('button', { name: '「TC-313-23 の端末」を削除' }).click()
  const confirm = page.getByRole('group', { name: '「TC-313-23 の端末」の削除の確認' })
  await expect(confirm).toContainText('消すと、このパスキーではログインできなくなります。')
  await confirm.getByRole('button', { name: '消す' }).click()

  await expect(page.getByText('「TC-313-23 の端末」を消しました。')).toBeVisible()
  await expect(row(page, 'TC-313-23 の端末')).toHaveCount(0)

  // 端末(仮想の認証器)にはパスキーが残ったままで、サーバーの登録だけが消えている
  await logout(page)
  await page.getByRole('button', { name: 'パスキーでログイン' }).click()

  await expect(page.getByRole('alert')).toHaveText(
    'パスキーを確かめられませんでした。このサイトに登録したパスキーを選んで、もう一度お試しください。',
  )
  await expect(page).toHaveURL(`${origin}/admin/login`)
  await page.goto(`${origin}/admin/passkeys`)
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
})
