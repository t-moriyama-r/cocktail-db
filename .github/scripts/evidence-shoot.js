#!/usr/bin/env node
// 証跡を頼むコメントの中身(evidence-request.sh が出す JSON)から、修正前・修正後を撮る手順を作り、順に流す(#600)。
// evidence-capture.yml の capture ジョブが使う。撮るのは公開リポジトリ t-moriyama-r/evidence-capture の scripts
// (capture.js・compose.js・http2html.js。github-attach.js は使わない)。
//
// 使い方:
//   node evidence-shoot.js plan <request.json>   … 流す手順を JSON で出すだけで、何も動かさない(テストと、ログで確かめる用)
//   node evidence-shoot.js run <request.json> --out <出力先> --tools <evidence-capture の scripts> --pw <playwright-core のある場所>
//        --before-dir <修正前の checkout> --before-url <修正前のアプリの URL> --after-dir <修正後の checkout> --after-url <修正後の URL>
//
// 出力先の中:
//   publish/  … evidence ブランチに置くものだけ(<連番 2 桁>-<kind>[-<幅>]-<before|after|compare>.<png|mp4|gif> と manifest.json)
//   texts/    … 画面・応答・コマンドの結果に出た文字。evidence-leak-check.sh で、ランナーのパス・ホスト名が出ていないかを見る。
//               コマンドの結果は、そのコマンドを流した checkout の絶対パスを <repo> に置き換えてから書く(#781)
//   work/     … 撮影の途中のもの(設定ファイルなど。ランナーの絶対パスが入るので、publish に入れない)
//
// 画面は、静止画を video: false で幅ごとに撮り(帯を重ねない)、動画は video の幅だけ別に録る(帯で修正前・修正後が分かるように)。
// 静止画の幅と動画の幅で帯の有無が変わらないよう、静止画はすべて同じ撮り方にする。
// 入れる値の "${env:名前}" は capture.js が環境変数に置き換えるので、頼むコメントから環境変数を画面に出させないよう、ここで断る。
//
// login: admin の画面(管理画面。#768)は、撮る回(修正前・修正後・幅ごと・動画)ごとに、その側の checkout で
// php artisan admin:login-link を流して 1 回だけ使えるログインの URL を出し(APP_URL はその側のアプリ。署名にホストが入るため)、
// capture.js の login で開いてから撮る。URL にはトークンが入るので、設定ファイルには "${env:EVIDENCE_LOGIN_URL}" とだけ書き、
// 値は capture.js の環境変数で渡す。ログイン中の画面(work/login.html)には URL を伏せ字の欄に入れるだけで、画面に出さない。
// capture.js の出力(texts/ に書く文字)に URL が出ていたら落とし、エラーの文(ジョブのログ)からは URL を伏せる。
// 撮っている capture.js(コミットを固定)は login.url の "${env:…}" を置き換えないので、置き換える fill で欄に入れて移る。
//
// steps のある画面は、撮る回(修正前・修正後 × 幅ごと・動画)ごとに、その側の DB を入れ直してから撮る(#802)。
// steps で保存する画面(管理画面の新規など)は、前の回で保存した行が残ると、同じ値での 2 回目が一意の制約の 422 で落ちるため。
// その側の 1 回目は、項目の先頭で入れたデータのまま撮る(入れ直しても同じなので省く)。steps の無い画面は DB を変えないので入れ直さない。
"use strict";
const fs = require("fs");
const path = require("path");
const { spawnSync } = require("child_process");

const SIDES = ["before", "after"];
const VIEWPORT_HEIGHT = 900;
const GIF_SECONDS = 15;
const GIF_WIDTH = 720;

// login に書ける人と、その人で入るユーザーの email(E2eSeeder が作る。e2e/tests/admin-layout.spec.ts の issueLoginLink と同じ)
const LOGINS = { admin: "e2e-admin@example.com" };
const LOGIN_ENV = "EVIDENCE_LOGIN_URL";
const LOGIN_MASK = "<ログインの URL>";
// ログインの URL を伏せ字の欄に入れ、送ると移るだけの画面。URL そのものは持たない
const LOGIN_PAGE = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>ログイン中</title></head>
<body style="margin:0;padding:24px;font:16px sans-serif;color:#444">
<form id="f" onsubmit="event.preventDefault(); location.assign(document.getElementById('u').value)">
<p>管理画面にログインしています</p>
<input id="u" type="password" autocomplete="off" style="opacity:0;width:1px;height:1px;border:0;padding:0">
<button id="go" type="submit" style="opacity:0">ログイン</button>
</form></body></html>
`;

const pad2 = (n) => String(n).padStart(2, "0");
const ENV_REF = /\$\{env:/;

// evidence-request.sh の steps(「操作: 値」が 1 つ)を capture.js の actions に写す
function toActions(steps, where) {
  return (steps || []).map((s, i) => {
    const [op, v] = Object.entries(s)[0];
    const w = `${where}.steps[${i}]`;
    const values = typeof v === "object" && v !== null ? Object.values(v) : [v];
    if (values.some((x) => ENV_REF.test(String(x)))) throw new Error(`${w}: \${env:…} は書けない(環境変数の値が画面に出るため)`);
    switch (op) {
      // capture.js には移る操作が無いので、ページの中で移り、移った先でヘルパーを入れ直す(cut の後の入れ直し)
      case "goto":
        return { eval: `location.assign(${JSON.stringify(v)})`, waitUrl: v.split("?")[0], wait: 1500, cut: true };
      case "click":
        return { click: v };
      case "fill":
        return { fill: [v.target, String(v.value)] };
      case "select":
        return { select: [v.target, String(v.value)] };
      case "press":
        return { press: v };
      case "hover":
        return { hover: v };
      case "wait_for":
        return { waitFor: v, cut: true };
      case "scroll_to":
        return { scrollTo: v };
      default:
        throw new Error(`${w}: 知らない操作 ${op}`);
    }
  });
}

function sideOf(opt, side) {
  return opt[side] || { dir: `<${side}-dir>`, url: `<${side}-url>` };
}

// 撮る手順を作る。opt.out などが無いとき(plan)は、置き場所を <…> のまま出す
function plan(req, opt = {}) {
  const out = opt.out || "<out>";
  const work = path.join(out, "work");
  const publish = path.join(out, "publish");
  const texts = path.join(out, "texts");
  let port = 9400;
  const nextPort = () => port++;
  const items = req.items.map((item, index) => {
    const id = `${pad2(index + 1)}-${item.kind}`;
    const where = `items[${index}]`;
    const title = `${item.cases.join(" ")}`;
    const steps = [{ type: "seed", seed: item.data.seed }];
    const files = [];
    const dir = path.join(work, id);
    const pub = (name) => {
      files.push(name);
      return path.join(publish, name);
    };
    if (item.kind === "screen") {
      const actions = toActions(item.steps, where);
      if (item.login !== undefined && !Object.hasOwn(LOGINS, item.login)) throw new Error(`${where}: 知らない login ${item.login}`);
      const loginPage = path.join(work, "login.html");
      // 撮る回ごとに足す。login は誰で入るか、config.login は capture.js がログインする手順(URL の値は持たない)
      const login = item.login ? { login: item.login, loginPage } : {};
      const loginConfig = item.login
        ? { login: { url: `file://${loginPage}`, actions: [{ fill: ["#u", `\${env:${LOGIN_ENV}}`] }, { click: "#go" }], waitUrlNot: "file:" } }
        : {};
      // 修正後の画面が 5xx を返すなら、エラーの画面を証跡として貼らずに撮れなかったことにする(#601)。
      // 修正前は直す前の不具合で 5xx のこともあるので見ない
      steps.push({ type: "probe", side: "after", path: item.path });
      const page = (name, extra) => ({ name, url: item.path, read: ["body"], actions, ...extra });
      // steps のある画面は、その側で 2 回目からの撮る回の前に、その側の DB を入れ直す(#802)
      const shot = { before: 0, after: 0 };
      const reseed = (side) => {
        if (actions.length > 0 && shot[side] > 0) steps.push({ type: "seed", seed: item.data.seed, side });
        shot[side] += 1;
      };
      for (const width of item.widths) {
        const wdir = path.join(dir, String(width));
        for (const side of SIDES) {
          reseed(side);
          steps.push({
            type: "capture", side, texts: path.join(texts, `${id}-${width}-${side}.txt`), ...login,
            config: { label: side, baseUrl: sideOf(opt, side).url, outDir: wdir, port: nextPort(), video: false,
              viewport: { width, height: VIEWPORT_HEIGHT }, ...loginConfig, pages: [page("page", { fullPage: true })] },
          });
          steps.push({ type: "copy", src: path.join(wdir, `${side}_page.png`), dest: pub(`${id}-${width}-${side}.png`) });
        }
        steps.push({
          type: "compose",
          config: { outDir: wdir, port: nextPort(), name: id, title: `${title} ${item.path}`,
            compare: [{ name: String(width), title: `${width}px`, before: "before_page.png", after: "after_page.png", ...(item.note ? { point: item.note } : {}) }] },
        });
        steps.push({ type: "copy", src: path.join(wdir, `${id}_compare_${width}.png`), dest: pub(`${id}-${width}-compare.png`) });
      }
      for (const width of item.video) {
        const vdir = path.join(dir, String(width), "video");
        for (const side of SIDES) {
          reseed(side);
          steps.push({
            type: "capture", side, texts: path.join(texts, `${id}-${width}-${side}-video.txt`), ...login,
            config: { label: side, baseUrl: sideOf(opt, side).url, outDir: vdir, port: nextPort(), video: true,
              viewport: { width, height: VIEWPORT_HEIGHT }, ...loginConfig,
              pages: [page("video", { title: `${title} ${item.path}`, caption: item.note || `${width}px で開いた画面` })] },
          });
          steps.push({ type: "video", src: path.join(vdir, `${side}.webm`), mp4: pub(`${id}-${width}-${side}.mp4`), gif: pub(`${id}-${width}-${side}.gif`) });
        }
      }
    } else if (item.kind === "api") {
      const r = item.request;
      for (const side of SIDES) {
        const raw = path.join(dir, `${side}.txt`);
        const html = path.join(dir, `${side}.html`);
        const args = ["-si", "--max-time", "60", "-X", r.method];
        const headers = { ...(r.headers || {}) };
        let body = null;
        if (r.body !== undefined && r.body !== null) {
          body = typeof r.body === "string" ? r.body : JSON.stringify(r.body);
          if (typeof r.body !== "string" && !Object.keys(headers).some((k) => k.toLowerCase() === "content-type")) headers["Content-Type"] = "application/json";
        }
        for (const [k, v] of Object.entries(headers)) args.push("-H", `${k}: ${v}`);
        if (body !== null) args.push("--data-binary", `@${path.join(dir, "request-body")}`);
        args.push(`${sideOf(opt, side).url}${r.path}`);
        steps.push({ type: "curl", side, args, body, bodyFile: path.join(dir, "request-body"), out: raw, texts: path.join(texts, `${id}-${side}.txt`) });
        steps.push({ type: "http2html", src: raw, out: html, request: `${r.method} ${r.path}`, body, bodyFile: path.join(dir, "request-body"), title });
        steps.push({
          type: "capture", side, texts: null,
          config: { label: side, outDir: dir, port: nextPort(), video: false, viewport: { width: 1000, height: VIEWPORT_HEIGHT },
            pages: [{ name: "api", url: `file://${html}`, fullPage: true }] },
        });
        steps.push({ type: "copy", src: path.join(dir, `${side}_api.png`), dest: pub(`${id}-${side}.png`) });
      }
      steps.push({ type: "compose", config: { outDir: dir, port: nextPort(), name: id, title: `${title} ${r.method} ${r.path}`,
        compare: [{ name: "api", before: "before_api.png", after: "after_api.png", ...(item.note ? { point: item.note } : {}) }] } });
      steps.push({ type: "copy", src: path.join(dir, `${id}_compare_api.png`), dest: pub(`${id}-compare.png`) });
    } else if (item.kind === "command") {
      for (const side of SIDES) {
        const raw = path.join(dir, `${side}.txt`);
        const html = path.join(dir, `${side}.html`);
        steps.push({ type: "command", side, run: item.run, workdir: item.workdir, out: raw, texts: path.join(texts, `${id}-${side}.txt`) });
        steps.push({ type: "text2html", src: raw, out: html, title: `${title} $ ${item.run}`, workdir: item.workdir });
        steps.push({
          type: "capture", side, texts: null,
          config: { label: side, outDir: dir, port: nextPort(), video: false, viewport: { width: 1000, height: VIEWPORT_HEIGHT },
            pages: [{ name: "command", url: `file://${html}`, fullPage: true }] },
        });
        steps.push({ type: "copy", src: path.join(dir, `${side}_command.png`), dest: pub(`${id}-${side}.png`) });
      }
      steps.push({ type: "compose", config: { outDir: dir, port: nextPort(), name: id, title: `${title} $ ${item.run}`,
        compare: [{ name: "command", before: "before_command.png", after: "after_command.png", layout: "column", ...(item.note ? { point: item.note } : {}) }] } });
      steps.push({ type: "copy", src: path.join(dir, `${id}_compare_command.png`), dest: pub(`${id}-compare.png`) });
    } else {
      throw new Error(`${where}: 知らない kind ${item.kind}`);
    }
    return { id, kind: item.kind, cases: item.cases, note: item.note || null, login: item.login || null, seed: item.data.seed, steps, files };
  });
  return { items };
}

// ---- 流す ----

// secrets に渡した文字(ログインの URL・トークン)は、エラーの文に出さない(ジョブのログは公開)
function mask(text, secrets = []) {
  let t = String(text || "");
  for (const x of secrets) if (x) t = t.split(x).join(LOGIN_MASK);
  return t;
}

// シェルを介さずに流す(引数は配列のまま渡す)。頼むコメントの run をシェルで流すのは runCommand だけにする
function sh(cmd, args, o = {}) {
  const { secrets, ...spawnOpt } = o;
  const r = spawnSync(cmd, args, { encoding: "utf8", maxBuffer: 64 * 1024 * 1024, ...spawnOpt, shell: false });
  if (r.error) throw r.error;
  if (r.status !== 0 && !o.allowFail) {
    // 出力にランナーのパスが入りうるが、ここはジョブのログ(公開)なので、末尾だけを出す。伏せる文字は伏せてから切る
    throw new Error(`${cmd} ${args[0] || ""} が ${r.status} で終わった\n${mask(r.stderr, secrets).slice(-2000)}`);
  }
  return r;
}

// その側のアプリに入る、1 回だけ使えるログインの URL を出す。URL と、伏せる文字(URL・クエリの値)を返す
function issueLogin(opt, side, who) {
  const base = opt[side].url;
  const r = sh("php", ["artisan", "admin:login-link", LOGINS[who]], {
    cwd: path.join(opt[side].dir, "backend"), env: { ...process.env, APP_URL: base },
  });
  const link = String(r.stdout).trim();
  let url = null;
  try {
    url = new URL(link);
  } catch {
    url = null;
  }
  // 値は出さない(出した物が URL でなくても、トークンが入っているかもしれないため)
  if (url === null || !link.startsWith(`${base}/`) || /\s/.test(link)) {
    throw new Error(`${side} の admin:login-link が、${base} のログインの URL を出さなかった`);
  }
  const secrets = [link, ...[...url.searchParams.values()].filter((v) => v.length >= 8)];
  return { link, secrets };
}

const esc = (s) => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

const REPO_MARK = "<repo>";
const REPO_NOTE = `出力の中の checkout の絶対パスは ${REPO_MARK} に置き換えている`;

// コマンドの出力に出る、そのコマンドを流した checkout の絶対パス(Vitest の RUN の行など)を <repo> に置き換える(#781)。
// 渡したパスと、シンボリックリンクをたどった実のパスの両方を見る。名前の続く別のディレクトリ(<checkout>-old など)は置き換えない。
// checkout の外の絶対パス(ホームなど)は残すので、evidence-leak-check.sh が今までどおり落とす。置き換えた数も返す
function maskRepo(text, dir) {
  const dirs = [dir];
  try {
    dirs.push(fs.realpathSync(dir));
  } catch {
    // 実のパスを取れなくても、渡したパスだけで置き換える
  }
  let count = 0;
  let t = String(text);
  // 長いものから置き換える(片方がもう片方の前の部分のとき、短いほうで先に切らない)
  for (const d of [...new Set(dirs)].filter((x) => x && x !== "/").sort((a, b) => b.length - a.length)) {
    // checkout 自身か、その下("/" で続く)のパスだけを置き換える。続く文字が区切り・終端でなければ別のディレクトリ(<checkout>+old・<checkout>日本語 など)
    const stop = "\\s\"'`:;,()\\[\\]<>";
    const re = new RegExp(`${d.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(/[^${stop}]*)?(?![^${stop}])`, "g");
    t = t.replace(re, (m, rest) => {
      // ".." で checkout の外へ出るパスは置き換えない(置き換えると /home/ が消えて evidence-leak-check.sh をすり抜ける)
      if (rest && rest.includes("..")) {
        const n = path.posix.normalize(`${d}${rest}`);
        if (n !== d && !n.startsWith(`${d}/`)) return m;
      }
      count++;
      return `${REPO_MARK}${rest || ""}`;
    });
  }
  return { text: t, count };
}

function text2html(src, out, title, workdir, masked) {
  const text = fs.readFileSync(src, "utf8");
  const note = masked ? `<p>${esc(REPO_NOTE)}</p>` : "";
  fs.writeFileSync(out, `<!doctype html><html lang="ja"><head><meta charset="utf-8"><style>
body { margin: 0; padding: 24px; background: #f5f5f5; font: 14px/1.6 "Noto Sans CJK JP",sans-serif; color: #222; }
h1 { font-size: 16px; margin: 0 0 4px; } p { margin: 0 0 12px; color: #757575; }
pre { margin: 0; padding: 12px 16px; background: #fff; border: 1px solid #ddd; border-radius: 6px; font: 13px/1.6 monospace; white-space: pre-wrap; word-break: break-all; }
</style></head><body><h1>${esc(title)}</h1><p>workdir: ${esc(workdir)}</p>${note}<pre>${esc(text)}</pre></body></html>\n`);
}

// コマンドに渡す環境変数。ジョブの環境(GITHUB_* など)をそのまま渡さず、動かすのに要るものだけにする
function commandEnv() {
  const keep = ["PATH", "HOME", "LANG", "TZ"];
  const env = { TERM: "dumb", NO_COLOR: "1" };
  for (const k of keep) if (process.env[k] !== undefined) env[k] = process.env[k];
  return env;
}

// 頼むコメントの run(信頼する人・bot が書いたものだけ。docs/evidence-request.md)を、その側の checkout でシェルとして流す。
// 失敗しても結果を撮るので、終了コードは呼ぶ側で書き出す。環境変数は commandEnv で絞る
function runCommand(run, cwd) {
  const r = spawnSync("bash", ["-c", `${run} 2>&1`], { cwd, env: commandEnv(), encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
  if (r.error) throw r.error;
  return r;
}

// side を渡すと、その側だけを入れ直す(撮る回ごとの入れ直し。#802)。渡さなければ両側に入れる
function seed(opt, name, only) {
  for (const side of only ? [only] : SIDES) {
    const backend = path.join(opt[side].dir, "backend");
    // 修正前に無いシーダー(この PR で足したもの)は、修正前だけ E2eSeeder で入れる(manifest に残す)
    const use = side === "before" && !fs.existsSync(path.join(backend, "database", "seeders", `${name}.php`)) ? "E2eSeeder" : name;
    sh("php", ["artisan", "migrate:fresh", "--force", "--seed", `--seeder=${use}`], { cwd: backend });
    opt.seeds[side] = use;
  }
}

// 開く画面の HTTP の状態だけを見る(画面の中身は撮るときに読む)。5xx か、つながらなければ落とす
function probe(opt, step) {
  const r = sh("curl", ["-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "60", `${opt[step.side].url}${step.path}`], { allowFail: true });
  const code = Number(r.stdout);
  if (r.status !== 0 || !(code > 0) || code >= 500) {
    throw new Error(`修正後の ${step.path} が ${code || "応答なし"} を返した(画面がエラーなので撮らない)`);
  }
}

function capture(opt, step) {
  const cfgFile = path.join(step.config.outDir, `${step.config.label}-${step.config.port}.json`);
  fs.mkdirSync(step.config.outDir, { recursive: true });
  fs.writeFileSync(cfgFile, JSON.stringify(step.config, null, 2));
  // ログインの URL は 1 回しか使えないので、撮る回ごとに出す。設定ファイルには書かず、環境変数で渡す
  let env = process.env;
  let secrets = [];
  if (step.login) {
    fs.mkdirSync(path.dirname(step.loginPage), { recursive: true });
    fs.writeFileSync(step.loginPage, LOGIN_PAGE);
    const issued = issueLogin(opt, step.side, step.login);
    env = { ...process.env, [LOGIN_ENV]: issued.link };
    secrets = issued.secrets;
  }
  // playwright-core を opt.pw から探させる(capture.js は cwd から探す)
  const r = sh("node", [path.join(opt.tools, "capture.js"), cfgFile], { cwd: opt.pw, env, secrets });
  if (secrets.some((x) => String(r.stdout).includes(x))) {
    throw new Error(`${step.side} の ${step.config.label} を撮った結果に、ログインの URL が出ていた(texts に書かずに止める)`);
  }
  if (step.texts) {
    const res = JSON.parse(r.stdout);
    const read = res.results.filter((x) => x.read).map((x) => Object.values(x.read).join("\n")).join("\n");
    fs.mkdirSync(path.dirname(step.texts), { recursive: true });
    fs.writeFileSync(step.texts, read);
  }
}

function run(req, opt) {
  const p = plan(req, opt);
  fs.mkdirSync(path.join(opt.out, "publish"), { recursive: true });
  fs.mkdirSync(path.join(opt.out, "texts"), { recursive: true });
  const manifest = { version: 1, before: opt.beforeSha || null, after: opt.afterSha || null, items: [] };
  // command の出力ごとに、checkout のパスを <repo> に置き換えたか(text2html が 1 行書くため)
  const masked = {};
  for (const item of p.items) {
    opt.seeds = {};
    console.error(`== ${item.id} (${item.cases.join(" ")})`);
    for (const step of item.steps) {
      if (step.type === "seed") seed(opt, step.seed, step.side);
      else if (step.type === "probe") probe(opt, step);
      else if (step.type === "capture") capture(opt, step);
      else if (step.type === "compose") {
        const cfgFile = path.join(step.config.outDir, `compose-${step.config.port}.json`);
        fs.mkdirSync(step.config.outDir, { recursive: true });
        fs.writeFileSync(cfgFile, JSON.stringify(step.config, null, 2));
        sh("node", [path.join(opt.tools, "compose.js"), cfgFile], { cwd: opt.pw });
      } else if (step.type === "copy") fs.copyFileSync(step.src, step.dest);
      else if (step.type === "curl") {
        fs.mkdirSync(path.dirname(step.out), { recursive: true });
        if (step.body !== null) fs.writeFileSync(step.bodyFile, step.body);
        const r = sh("curl", step.args);
        fs.writeFileSync(step.out, r.stdout);
        fs.copyFileSync(step.out, step.texts);
      } else if (step.type === "http2html") {
        const args = [path.join(opt.tools, "http2html.js"), step.src, step.out, "--request", step.request, "--title", step.title];
        if (step.body !== null) args.push("--request-body", step.bodyFile);
        sh("node", args);
      } else if (step.type === "command") {
        fs.mkdirSync(path.dirname(step.out), { recursive: true });
        const r = runCommand(step.run, path.join(opt[step.side].dir, step.workdir));
        const m = maskRepo(r.stdout, opt[step.side].dir);
        masked[step.out] = m.count > 0;
        fs.writeFileSync(step.out, `${m.text}\n(終了コード ${r.status})\n`);
        fs.copyFileSync(step.out, step.texts);
      } else if (step.type === "text2html") text2html(step.src, step.out, step.title, step.workdir, masked[step.src]);
      else if (step.type === "video") {
        sh("ffmpeg", ["-hide_banner", "-loglevel", "error", "-y", "-i", step.src, "-c:v", "libx264", "-pix_fmt", "yuv420p",
          "-vf", "scale=trunc(iw/2)*2:trunc(ih/2)*2", "-movflags", "+faststart", "-threads", "1", "-an", step.mp4]);
        sh("ffmpeg", ["-hide_banner", "-loglevel", "error", "-y", "-t", String(GIF_SECONDS), "-i", step.src, "-vf",
          `fps=6,scale='min(${GIF_WIDTH},iw)':-2:flags=lanczos,split[a][b];[a]palettegen[p];[b][p]paletteuse`, step.gif]);
      } else throw new Error(`知らない手順 ${step.type}`);
    }
    manifest.items.push({ id: item.id, kind: item.kind, cases: item.cases, note: item.note, seed: { ...opt.seeds }, files: item.files });
  }
  fs.writeFileSync(path.join(opt.out, "publish", "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
}

function parseArgs(argv) {
  const o = {};
  for (let i = 0; i < argv.length; i += 2) {
    if (!argv[i].startsWith("--") || argv[i + 1] === undefined) throw new Error(`引数の誤り: ${argv[i]}`);
    o[argv[i].slice(2)] = argv[i + 1];
  }
  return o;
}

function main() {
  const [cmd, file, ...rest] = process.argv.slice(2);
  if (!["plan", "run"].includes(cmd) || !file) {
    console.error("使い方: evidence-shoot.js plan <request.json> | run <request.json> --out … --tools … --pw … --before-dir … --before-url … --after-dir … --after-url …");
    process.exit(2);
  }
  const req = JSON.parse(fs.readFileSync(file, "utf8"));
  if (cmd === "plan") {
    console.log(JSON.stringify(plan(req), null, 2));
    return;
  }
  const a = parseArgs(rest);
  for (const k of ["out", "tools", "pw", "before-dir", "before-url", "after-dir", "after-url"]) {
    if (!a[k]) throw new Error(`--${k} が無い`);
  }
  const abs = (p) => path.resolve(p);
  run(req, {
    out: abs(a.out), tools: abs(a.tools), pw: abs(a.pw), beforeSha: a["before-sha"], afterSha: a["after-sha"],
    before: { dir: abs(a["before-dir"]), url: a["before-url"].replace(/\/$/, "") },
    after: { dir: abs(a["after-dir"]), url: a["after-url"].replace(/\/$/, "") },
  });
}

try {
  main();
} catch (e) {
  console.error(e.message || e);
  process.exit(1);
}
