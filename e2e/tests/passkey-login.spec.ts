import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// パスキーのログインの画面(#312)。Chromium の仮想の認証器(CDP の WebAuthn)で、本物の BE とブラウザの WebAuthn を通す。
//
// パスキーは RP ID(ドメイン)に結び付き、BE は RP ID と受け付けるオリジンを APP_URL から取る(docs/decisions/passkey.md)。
// RP ID に IP アドレスは使えないので、ここでは E2E の既定の 127.0.0.1 ではなく、APP_URL(.env.example・CI は
// http://localhost:8000)と同じ localhost で開く。

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

/**
 * 非常用のログイン URL でログインし、仮想の認証器にパスキーを登録して、登録したパスキーの ID を返す。
 * ログインの画面を確かめるテストなので、登録の画面(#313。passkey-management.spec.ts)は通さず、登録の API(docs/decisions/passkey.md の 3)をページの中から呼ぶ。
 */
async function registerPasskey(page: Page, origin: string): Promise<number> {
  await page.goto(issueLoginLink(origin))
  await expect(page.getByRole('navigation', { name: '管理画面のメニュー' })).toBeVisible()

  return page.evaluate(async () => {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')
    const post = async (path: string, body?: unknown) => {
      const response = await fetch(path, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!response.ok) {
        throw new Error(`${path}: ${response.status} ${await response.text()}`)
      }
      return response.json()
    }
    const { options } = await post('/api/admin/passkeys/registration-options')
    const credential = await navigator.credentials.create({
      publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options),
    })
    if (!(credential instanceof PublicKeyCredential)) {
      throw new Error('パスキーを作れなかった')
    }
    const saved = await post('/api/admin/passkeys', { name: 'E2E の仮想の認証器', credential: credential.toJSON() })
    // JsonResource は { data: … } で包んで返す
    return saved.data.id
  })
}

async function logout(page: Page) {
  await page.getByRole('button', { name: 'ログアウト' }).click()
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
}

test('TC-312-15: パスキーを登録 → ログアウト → 「パスキーでログイン」で、メニューに戻ってログイン中になる', async ({
  page,
  baseURL,
}) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  await registerPasskey(page, origin)
  await logout(page)

  await page.getByRole('button', { name: 'パスキーでログイン' }).click()

  await expect(page).toHaveURL(`${origin}/admin`)
  await expect(page.getByText('E2E の管理者 でログイン中')).toBeVisible()
})

test('TC-312-16: 未ログインで開こうとした管理画面の URL へ、パスキーでログインした後に戻る', async ({ page, baseURL }) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  await registerPasskey(page, origin)
  await logout(page)

  await page.goto(`${origin}/admin/nope?page=2`)
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
  await page.getByRole('button', { name: 'パスキーでログイン' }).click()

  await expect(page).toHaveURL(`${origin}/admin/nope?page=2`)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  await expect(page.getByText('E2E の管理者 でログイン中')).toBeVisible()
})

test('TC-312-17: 消したパスキーでログインしようとすると、確かめられなかったと出してログインの画面に残る', async ({
  page,
  baseURL,
}) => {
  const origin = passkeyOrigin(baseURL!)
  await addVirtualAuthenticator(page)
  const id = await registerPasskey(page, origin)
  // 端末(仮想の認証器)にはパスキーが残ったまま、サーバーの登録だけを消す
  const deleted = await page.evaluate(async (passkeyId) => {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')
    const response = await fetch(`/api/admin/passkeys/${passkeyId}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
    })
    return response.status
  }, id)
  expect(deleted).toBe(204)
  await logout(page)

  await page.getByRole('button', { name: 'パスキーでログイン' }).click()

  await expect(page.getByRole('alert')).toHaveText(
    'パスキーを確かめられませんでした。このサイトに登録したパスキーを選んで、もう一度お試しください。',
  )
  await expect(page).toHaveURL(`${origin}/admin/login`)
  await page.goto(`${origin}/admin`)
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
})
