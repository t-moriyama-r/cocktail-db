/** Node の process のうち、ここで使うものだけの型(画面のコードの tsconfig には Node の型を入れていないため) */
type NodeProcess = { cpuUsage: () => { user: number; system: number } }

/**
 * テストを動かしているプロセスが、ここまでに使った CPU 時間(ミリ秒。ユーザーとシステムの合計)。
 *
 * 経過時間(performance.now)は、同じマシンで動くほかの処理(並べて流すほかのテストのファイル・CI のほかのジョブ)に
 * CPU を取られた分も数えるので、負荷がかかると処理そのものは同じでも何倍にも延びる(#663)。CPU 時間は、
 * このプロセスが実際に計算した分だけを数えるので、処理の重さを負荷によらずに測れる。
 * Vitest はテストのファイルごとに子プロセス(既定の pool の forks)で動かすので、ほかのファイルの CPU 時間は混ざらない
 */
export const cpuTimeMs = (): number => {
  const { user, system } = (globalThis as unknown as { process: NodeProcess }).process.cpuUsage()
  return (user + system) / 1000
}
