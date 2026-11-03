#!/usr/bin/env bash
# 下载后执行：bash setup.sh [项目目录]
set -euo pipefail

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    printf '%s\n' '用法：bash setup.sh [项目目录]' '检测到 Laravel 根目录时确认是否在该目录安装；否则询问是否新建 Laravel 10 和安装目录。' '需要 PHP 8.1+、Composer、Node.js/npm；安装时会隐藏输入 GitHub classic PAT（read:packages 权限）。'
    exit 0
fi
if [[ $# -gt 1 ]]; then
    printf '%s\n' '用法：bash setup.sh [项目目录]' >&2
    exit 1
fi
command -v php >/dev/null 2>&1 || { printf '请先安装 PHP 8.1+。\n' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { printf '请使用 PHP 8.1+。\n' >&2; exit 1; }

is_laravel() {
    [[ -f "$1/artisan" && -f "$1/composer.json" && -f "$1/bootstrap/app.php" ]] &&
        php -r '$json = json_decode(file_get_contents($argv[1]), true); exit(isset($json["require"]["laravel/framework"]) ? 0 : 1);' "$1/composer.json"
}

confirm() {
    local answer
    read -r -p "$1 [y/N] " answer || return 1
    [[ "$answer" == "y" || "$answer" == "Y" || "$answer" == "yes" || "$answer" == "YES" ]]
}

project_dir="${1:-.}"
new_project=false
if is_laravel "$project_dir"; then
    project_dir="$(cd -- "$project_dir" && pwd)"
    if ! confirm "检测到 Laravel 根目录：${project_dir}，是否在该目录安装 IM 演示？"; then
        printf '已取消，未修改项目。\n'
        exit 0
    fi
else
    if ! confirm '当前目录不是 Laravel 根目录，是否全新安装 Laravel 和 IM 演示？'; then
        printf '已取消，未修改项目。\n'
        exit 0
    fi
    read -r -p '请输入安装目录 [./laravel-im-demo]：' install_dir || exit 1
    project_dir="${install_dir:-./laravel-im-demo}"
    if [[ -e "$project_dir" && ! -d "$project_dir" ]]; then
        printf '安装目标不是目录：%s\n' "$project_dir" >&2
        exit 1
    fi
    if [[ -d "$project_dir" && -n "$(ls -A -- "$project_dir")" ]]; then
        printf '全新安装需要空目录，目标目录已有内容：%s\n' "$project_dir" >&2
        exit 1
    fi
    new_project=true
fi

for executable in composer npm; do
    command -v "$executable" >/dev/null 2>&1 || { printf '请先安装 %s。\n' "$executable" >&2; exit 1; }
done
php -r 'foreach (["pdo_sqlite", "mbstring"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "请启用 PHP 扩展：$extension\n"); exit(1); } }'

export GIORGIO_IM_HTTP_PORT="${GIORGIO_IM_HTTP_PORT:-8001}"
export GIORGIO_IM_SOCKET_PORT="${GIORGIO_IM_SOCKET_PORT:-9501}"
export GIORGIO_IM_REDIS_PORT="${GIORGIO_IM_REDIS_PORT:-16380}"
for port in "$GIORGIO_IM_HTTP_PORT" "$GIORGIO_IM_SOCKET_PORT" "$GIORGIO_IM_REDIS_PORT"; do
    if [[ ! "$port" =~ ^[0-9]+$ ]] || (( 10#$port < 1 || 10#$port > 65535 )); then
        printf '端口必须是 1–65535：%s\n' "$port" >&2
        exit 1
    fi
done
if [[ "$GIORGIO_IM_HTTP_PORT" == "$GIORGIO_IM_SOCKET_PORT" || "$GIORGIO_IM_HTTP_PORT" == "$GIORGIO_IM_REDIS_PORT" || "$GIORGIO_IM_SOCKET_PORT" == "$GIORGIO_IM_REDIS_PORT" ]]; then
    printf 'HTTP、Socket、Redis 端口不能相同。\n' >&2
    exit 1
fi

# 不将输入的 token 输出到 shell 调试日志，也不写入项目文件。
set +x
printf '%s\n' '前端包需要 GitHub classic PAT，请在 https://github.com/settings/tokens 创建并勾选 read:packages。'
npm_token=''
while [[ -z "$npm_token" ]]; do
    if ! IFS= read -r -s -p '请输入 GitHub token（输入不显示）：' npm_token; then
        printf '\n未读取到 token，已取消安装。\n' >&2
        exit 1
    fi
    printf '\n'
    if [[ -z "$npm_token" || "$npm_token" == *[[:space:]]* ]]; then
        printf 'Token 不能为空或包含空白字符，请重新输入。\n' >&2
        npm_token=''
    fi
done

if [[ "$new_project" == true ]]; then
    composer create-project --prefer-dist --no-interaction laravel/laravel "$project_dir"
fi
cd -- "$project_dir"
if [[ -f bootstrap/cache/config.php ]]; then
    php artisan config:clear
fi

php <<'GIORGIO_IM_SETUP_PHP'
<?php

function fail(string $message): void
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

$root = getcwd();
foreach (['artisan', 'composer.json', 'bootstrap/app.php', 'config/app.php', 'routes/api.php', 'routes/web.php', 'package.json'] as $path) {
    if (! is_file($root.'/'.$path)) {
        fail('缺少 Laravel 项目文件：'.$path);
    }
}
$files = [
    'resources/views/im.blade.php' => <<<'GIORGIO_IM_FILE_0'
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giorgio IM · 即时通讯</title>
    <link rel="stylesheet" href="{{ asset('im-demo/app.css') }}">
</head>
<body>
<main class="demo-shell">
    <section class="demo-card" aria-labelledby="im-account">
        <nav class="demo-nav" aria-label="首页">
            <a class="demo-brand" href="{{ url('/im') }}">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M25 5H7a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h3v5l7-5h8a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M10 12h12M10 17h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                GIORGIO <span>IM</span>
            </a>
        </nav>
        <h1 id="im-account">创建你的账号</h1>
        <p class="demo-description">让每一次对话，真正连接起来。</p>
        <div class="demo-auth-tabs" id="im-auth-tabs" role="tablist" aria-label="账号入口">
            <button type="button" id="im-register-tab" role="tab" aria-selected="true" aria-controls="im-login">注册</button>
            <button type="button" id="im-login-tab" role="tab" aria-selected="false" aria-controls="im-login">登录</button>
        </div>
        <form class="demo-form" id="im-login" data-require-tls="{{ config('im.require_tls') ? 'true' : 'false' }}" data-allow-insecure-localhost="{{ config('im.allow_insecure_loopback') && app()->environment('local', 'testing') ? 'true' : 'false' }}">
            <label id="im-name-field">昵称<input name="name" maxlength="80" autocomplete="nickname" placeholder="你的名字" required></label>
            <label>邮箱<input name="email" type="email" maxlength="255" autocomplete="email" placeholder="you@example.com" required></label>
            <label>密码<input name="password" type="password" minlength="8" maxlength="255" autocomplete="new-password" placeholder="至少 8 个字符" required></label>
            <label id="im-confirm-field">确认密码<input name="password_confirmation" type="password" maxlength="255" autocomplete="new-password" placeholder="再次输入密码" required></label>
            <button type="submit">注册并进入聊天 ↗</button>
        </form>
        <button class="demo-logout" id="im-logout" hidden>退出登录</button>
        <p class="demo-feedback" id="im-feedback" role="status" aria-live="polite"></p>
        <div class="demo-status" id="im-state" role="status">未登录</div>
        <p class="demo-note">注册后自动加入 <strong>GIORGIO-IM</strong> 群聊，<br>并与所有已有用户互加好友，立即开始私聊。</p>
    </section>
</main>
<script type="module" src="{{ asset('im-demo/app.js') }}"></script>
</body>
</html>
GIORGIO_IM_FILE_0,
    'resources/js/im-demo.ts' => <<<'GIORGIO_IM_FILE_1'
import { assertImTransport, createConnectedIM, ImHttpError, type ConnectedIM, type ImConnectionState, type ImTransportOptions } from '@m-finder/giorgio-im'
// @ts-ignore
import '../css/im-demo.css'

const form = document.querySelector<HTMLFormElement>('#im-login')!
const feedback = document.querySelector<HTMLElement>('#im-feedback')!
const state = document.querySelector<HTMLElement>('#im-state')!
const account = document.querySelector<HTMLElement>('#im-account')!
const logout = document.querySelector<HTMLButtonElement>('#im-logout')!
const tabs = document.querySelector<HTMLElement>('#im-auth-tabs')!
const registerTab = document.querySelector<HTMLButtonElement>('#im-register-tab')!
const loginTab = document.querySelector<HTMLButtonElement>('#im-login-tab')!
const nameField = document.querySelector<HTMLElement>('#im-name-field')!
const confirmField = document.querySelector<HTMLElement>('#im-confirm-field')!
const nickname = form.elements.namedItem('name') as HTMLInputElement
const email = form.elements.namedItem('email') as HTMLInputElement
const password = form.elements.namedItem('password') as HTMLInputElement
const confirmation = form.elements.namedItem('password_confirmation') as HTMLInputElement
const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]')!
const transport: ImTransportOptions = {
  requireTLS: form.dataset.requireTls !== 'false',
  allowInsecureLocalhost: form.dataset.allowInsecureLocalhost === 'true',
}
function request(path: string, options: RequestInit): Promise<Response> {
  const url = `/api/im${path}`
  assertImTransport(url, 'http', transport)
  return fetch(url, { ...options, redirect: 'error' })
}
let im: ConnectedIM | undefined
let bearer = ''
const sessionKey = 'giorgio-im-demo:session'
let mode: 'register' | 'login' = 'register'
const statuses: Record<ImConnectionState, string> = { connecting: '正在连接', online: '实时连接已建立', reconnecting: '正在重连，连接后补收消息', offline: '连接已断开', closed: '已退出' }
function setMode(value: typeof mode): void {
  mode = value
  const registering = mode === 'register'
  registerTab.setAttribute('aria-selected', String(registering))
  loginTab.setAttribute('aria-selected', String(!registering))
  nameField.hidden = confirmField.hidden = !registering
  nickname.required = confirmation.required = registering
  password.minLength = registering ? 8 : 1
  password.autocomplete = registering ? 'new-password' : 'current-password'
  password.placeholder = registering ? '至少 8 个字符' : '输入你的密码'
  confirmation.setCustomValidity('')
  account.textContent = registering ? '创建你的账号' : '欢迎回来'
  submit.textContent = registering ? '注册并进入聊天 ↗' : '登录并进入聊天 ↗'
  feedback.textContent = ''
}
registerTab.addEventListener('click', () => setMode('register'))
loginTab.addEventListener('click', () => setMode('login'))
confirmation.addEventListener('input', () => confirmation.setCustomValidity(''))
password.addEventListener('input', () => confirmation.setCustomValidity(''))

function clearSession(): void {
  try { sessionStorage.removeItem(sessionKey) } catch { /* Storage may be disabled by the browser. */ }
}

async function connect(accountName: string): Promise<void> {
  im = await createConnectedIM({
    token: () => bearer,
    transport,
    onConnectionChange(value) { state.textContent = statuses[value]; state.dataset.state = value },
    onError(error) { feedback.textContent = error.message },
    widget: { locale: 'zh-CN' },
  })
  try { sessionStorage.setItem(sessionKey, JSON.stringify({ token: bearer, account: accountName })) } catch { /* Keep login usable without browser storage. */ }
  form.hidden = tabs.hidden = true
  logout.hidden = false
  account.textContent = accountName
  password.value = confirmation.value = ''
  im.open()
}

async function restoreSession(): Promise<void> {
  if (im || submit.disabled) return
  let saved: { token: string; account: string }
  try {
    saved = JSON.parse(sessionStorage.getItem(sessionKey) || 'null')
    if (!saved || typeof saved.token !== 'string' || !saved.token || typeof saved.account !== 'string') return
  } catch { clearSession(); return }
  bearer = saved.token
  submit.disabled = registerTab.disabled = loginTab.disabled = true
  state.textContent = '正在恢复登录'
  feedback.textContent = ''
  try {
    await connect(saved.account)
  } catch (error) {
    bearer = ''
    form.hidden = tabs.hidden = false
    logout.hidden = true
    setMode('login')
    if (error instanceof ImHttpError && [401, 403].includes(error.status)) {
      clearSession()
      feedback.textContent = error.status === 401 ? '登录已过期，请重新登录。' : error.message
      state.textContent = '未登录'
    } else {
      feedback.textContent = '恢复连接失败，请刷新页面重试。' + (error instanceof Error ? error.message : '')
      state.textContent = '连接恢复失败'
    }
    delete state.dataset.state
  } finally { submit.disabled = registerTab.disabled = loginTab.disabled = false }
}

async function login(event?: Event): Promise<void> {
  event?.preventDefault()
  if (im || submit.disabled) return
  if (mode === 'register' && password.value !== confirmation.value) {
    confirmation.setCustomValidity('两次输入的密码不一致。')
    confirmation.reportValidity()
    return
  }
  const operation = mode
  submit.disabled = registerTab.disabled = loginTab.disabled = true
  feedback.textContent = ''
  let authenticated = false
  try {
    const response = await request(`/${operation}`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email.value, password: password.value, ...(operation === 'register' ? { name: nickname.value, password_confirmation: confirmation.value } : {}) }) })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join('；') || data.message || '操作失败，请重试。')
    bearer = data.token
    authenticated = true
    await connect(operation === 'register' ? nickname.value : email.value)
  } catch (error) {
    if (authenticated) {
      await request('/logout', { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${bearer}` } }).catch(() => {})
      bearer = ''
      clearSession()
      setMode('login')
    }
    feedback.textContent = (authenticated && operation === 'register' ? '账号已创建，请登录重试。' : '') + (error instanceof Error ? error.message : '操作失败，请重试。')
    state.textContent = '未登录'
    delete state.dataset.state
  } finally { submit.disabled = registerTab.disabled = loginTab.disabled = false }
}
form.addEventListener('submit', login)
logout.addEventListener('click', async () => {
  logout.disabled = true
  clearSession()
  im?.destroy()
  im = undefined
  await request('/logout', { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${bearer}` } }).catch(() => {})
  bearer = ''
  form.hidden = tabs.hidden = false
  logout.hidden = true
  logout.disabled = false
  setMode('login')
})
window.addEventListener('pagehide', () => { im?.destroy(); im = undefined })
window.addEventListener('pageshow', event => { if (event.persisted) void restoreSession() })
void restoreSession()
GIORGIO_IM_FILE_1,
    'resources/css/im-demo.css' => <<<'GIORGIO_IM_FILE_2'
:root {
  font-family: Figtree, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  color: #111827;
  background: #f3f4f6;
  font-synthesis: none;
  -webkit-font-smoothing: antialiased;
  --surface: #fff;
  --border: #e5e7eb;
  --muted: #6b7280;
  --field: #fff;
  --accent: #ff2d20;
}
* { box-sizing: border-box; }
body { margin: 0; background-image: radial-gradient(#00000012 1px, transparent 1px); background-size: 30px 30px; }
button, input { font: inherit; }
button { cursor: pointer; }
a { color: inherit; text-decoration: none; }
[hidden] { display: none !important; }
.demo-shell { min-height: 100vh; min-height: 100svh; display: flex; align-items: center; justify-content: center; padding: 32px 20px; }
.demo-card { width: 100%; max-width: 440px; padding: 32px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); box-shadow: 0 25px 50px -12px #6b728033; }
.demo-nav { display: flex; justify-content: center; margin-bottom: 28px; }
.demo-brand { display: inline-flex; gap: 8px; align-items: center; font-size: 17px; font-weight: 650; letter-spacing: 1.5px; }
.demo-brand svg { width: 34px; height: 34px; margin-right: 3px; color: var(--accent); }
.demo-brand span { color: var(--accent); }
.demo-card h1 { margin: 0; font-size: 23px; font-weight: 600; text-align: center; overflow-wrap: anywhere; }
.demo-description { margin: 10px 0 24px; font-size: 13px; text-align: center; color: var(--muted); }
.demo-auth-tabs { display: flex; gap: 4px; padding: 4px; margin-bottom: 24px; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb; }
.demo-auth-tabs button { flex: 1; padding: 9px; border: 0; border-radius: 5px; color: var(--muted); background: transparent; font-size: 13px; }
.demo-auth-tabs button[aria-selected=true] { background: var(--surface); color: var(--accent); box-shadow: 0 1px 3px #00000012; font-weight: 600; }
.demo-form { display: grid; gap: 17px; }
.demo-form label { display: grid; gap: 7px; font-size: 13px; font-weight: 500; }
.demo-form input { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: var(--field); color: inherit; font-size: 14px; }
.demo-form input::placeholder { color: #9ca3af; font-size: 13px; }
.demo-form button, .demo-logout { width: 100%; padding: 12px 16px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font-size: 13px; font-weight: 600; transition: background .15s; }
.demo-form button:hover, .demo-logout:hover { background: #e3261b; }
button:disabled { cursor: wait; opacity: .55; }
.demo-form input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.demo-feedback { margin: 16px 0 0; font-size: 12px; line-height: 1.7; color: #dc2626; overflow-wrap: anywhere; }
.demo-feedback:empty { display: none; }
.demo-status { display: flex; justify-content: center; gap: 7px; align-items: center; margin-top: 20px; color: var(--muted); font-size: 12px; }
.demo-status::before { content: ""; width: 6px; height: 6px; flex-shrink: 0; border-radius: 50%; background: #9ca3af; }
.demo-status[data-state=online]::before { background: #22c55e; }
.demo-note { margin: 22px 0 0; padding-top: 20px; border-top: 1px solid var(--border); text-align: center; font-size: 12px; line-height: 1.9; color: var(--muted); }
.demo-note strong { font-weight: 500; }
@media (max-width: 480px) { .demo-shell { padding: 24px 16px; } .demo-card { padding: 26px 22px; } }
@media (prefers-color-scheme: dark) {
  :root { color: #f3f4f6; background: #111827; --surface: #1f2937; --border: #374151; --muted: #9ca3af; --field: #111827; color-scheme: dark; }
  body { background-image: radial-gradient(#ffffff12 1px, transparent 1px); }
  .demo-card { box-shadow: 0 25px 50px -12px #00000040; }
  .demo-auth-tabs { background: #111827; }
  .demo-form input { border-color: #4b5563; }
  .demo-feedback { color: #fca5a5; }
}
GIORGIO_IM_FILE_2,
    'vite.im.config.ts' => <<<'GIORGIO_IM_FILE_3'
import { defineConfig } from 'vite'

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'public/im-demo',
    emptyOutDir: true,
    rollupOptions: { input: 'resources/js/im-demo.ts', output: { entryFileNames: 'app.js', assetFileNames: 'app.[ext]' } },
  },
})
GIORGIO_IM_FILE_3,
    'app/Http/Controllers/Im/AuthController.php' => <<<'GIORGIO_IM_FILE_4'
<?php

namespace App\Http\Controllers\Im;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Im\DemoOnboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, DemoOnboarding $im): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:255|confirmed',
        ], [
            'email.unique' => '这个邮箱已注册，请直接登录。',
            'password.min' => '密码至少需要 8 个字符。',
            'password.confirmed' => '两次输入的密码不一致。',
        ]);
        $token = DB::transaction(function () use ($data, $im) {
            DB::table('im_communities')->insertOrIgnore(['name' => 'GIORGIO-IM']);
            DB::table('im_communities')->where('name', 'GIORGIO-IM')->lockForUpdate()->first();
            if (User::where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => '这个邮箱已注册，请直接登录。']);
            }
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
            $im->onboard($user);

            return $user->createToken('giorgio-im', ['im'], now()->addDay())->plainTextToken;
        }, 3);

        return response()->json(['token' => $token], 201);
    }

    public function login(Request $request): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:255']);

        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => '邮箱或密码不正确。']);
            }
            abort_unless($user->isEnabled(), 403, '账号已被禁用，请联系管理员。');

            return ['token' => $user->createToken('giorgio-im', ['im'], now()->addDay())->plainTextToken];
        }, 3);
    }

    public function logout(Request $request): array
    {
        $request->user()->currentAccessToken()?->delete();

        return ['ok' => true];
    }
}
GIORGIO_IM_FILE_4,
    'app/Http/Middleware/ImAccess.php' => <<<'GIORGIO_IM_FILE_5'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ImAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()->tokenCan('im'), 403);

        return $next($request);
    }
}
GIORGIO_IM_FILE_5,
    'app/Services/Im/DemoOnboarding.php' => <<<'GIORGIO_IM_FILE_6'
<?php

namespace App\Services\Im;

use App\Models\User;
use GiorgioImSocket\Services\ImService;
use Illuminate\Support\Facades\DB;

/** Demo policy, called by registration inside the community transaction. */
class DemoOnboarding
{
    public function onboard(User $user): void
    {
        $im = app(ImService::class);
        $im->profile($user->id);
        $category = $im->category($user->id);
        foreach (User::where('id', '!=', $user->id)->orderBy('id')->cursor() as $friend) {
            $friendCategory = $im->category($friend->id);
            DB::table('im_friends')->insert([
                ['user_id' => $user->id, 'friend_id' => $friend->id, 'group_id' => $category],
                ['user_id' => $friend->id, 'friend_id' => $user->id, 'group_id' => $friendCategory],
            ]);
            $im->event([$user->id], 'contact.updated', ['contact' => [...$im->user($friend->id), 'type' => 'friend'], 'groupId' => $category]);
            $im->event([$friend->id], 'contact.updated', ['contact' => [...$im->user($user->id), 'type' => 'friend'], 'groupId' => $friendCategory]);
        }
        $groupId = DB::table('im_communities')->where('name', 'GIORGIO-IM')->value('group_id');
        if (! $groupId) {
            $groupId = DB::table('im_groups')->insertGetId([
                'owner_id' => User::orderBy('id')->value('id'), 'groupname' => 'GIORGIO-IM',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('im_communities')->where('name', 'GIORGIO-IM')->update(['group_id' => $groupId]);
            foreach (User::orderBy('id')->cursor() as $member) {
                DB::table('im_group_members')->insert(['group_id' => $groupId, 'user_id' => $member->id, 'joined_at' => now()]);
            }
        } else {
            DB::table('im_group_members')->insert(['group_id' => $groupId, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        $im->groupEvent((int) $groupId);
    }
}
GIORGIO_IM_FILE_6,
    'app/Services/Im/SanctumIdentity.php' => <<<'GIORGIO_IM_FILE_7'
<?php

namespace App\Services\Im;

use App\Models\User;
use GiorgioImSocket\Contracts\SocketIdentity;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SanctumIdentity implements SocketIdentity
{
    public function claims(Request $request): array
    {
        return ['token_id' => $request->user()->currentAccessToken()?->getKey()];
    }

    public function resolve(array $ticket): ?int
    {
        try {
            if (empty($ticket['token_id']) || ! isset($ticket['user_id'])) {
                return null;
            }
            $token = PersonalAccessToken::find($ticket['token_id']);
            if (! $token || ! $token->can('im') || ($token->expires_at && $token->expires_at->isPast())
                || (config('sanctum.expiration') && $token->created_at->lte(now()->subMinutes((int) config('sanctum.expiration'))))
                || ! $token->tokenable instanceof User || ! $token->tokenable->isEnabled() || $token->tokenable->id !== $ticket['user_id']) {
                return null;
            }

            return $token->tokenable->id;
        } catch (\Throwable) {
            return null;
        }
    }
}
GIORGIO_IM_FILE_7,
    'app/Providers/ImServiceProvider.php' => <<<'GIORGIO_IM_FILE_8'
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ImServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('im-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('im-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
GIORGIO_IM_FILE_8,
    'app/Models/User.php' => <<<'GIORGIO_IM_FILE_9'
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $attributes = ['status' => self::STATUS_ENABLED];

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'integer',
    ];
}
GIORGIO_IM_FILE_9,
    'routes/im.php' => <<<'GIORGIO_IM_FILE_10'
<?php

use App\Http\Controllers\Im\AuthController;
use App\Http\Middleware\ImAccess;
use GiorgioSocket\Http\Middleware\RequireSecureTransport;
use Illuminate\Support\Facades\Route;

// Registration and login belong to this demo application, not to the IM extension.
Route::prefix('im')->middleware(RequireSecureTransport::class.':im')->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:im-register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:im-login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', ImAccess::class, 'throttle:im-api']);
});
GIORGIO_IM_FILE_10,
    'config/im.php' => <<<'GIORGIO_IM_FILE_11'
<?php

return [
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/im',
        'middleware' => ['api'],
        'auth_middleware' => ['auth:sanctum', \App\Http\Middleware\ImAccess::class],
    ],
    'socket_identity' => \App\Services\Im\SanctumIdentity::class,
];
GIORGIO_IM_FILE_11,
    'database/migrations/2026_10_31_000002_create_im_communities_table.php' => <<<'GIORGIO_IM_FILE_12'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('im_communities', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->foreignId('group_id')->nullable()->constrained('im_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('im_communities');
    }
};
GIORGIO_IM_FILE_12,
    'database/migrations/2026_10_31_000005_add_status_to_users_table.php' => <<<'GIORGIO_IM_FILE_13'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(1)->comment('1 enabled, 0 disabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
GIORGIO_IM_FILE_13,
];

// 先计算所有文件改动，再备份和写入。
$app = file_get_contents($root.'/config/app.php');
$provider = 'App\Providers\ImServiceProvider::class';
if (! str_contains($app, $provider)) {
    $anchor = 'App\Providers\AppServiceProvider::class,';
    if (substr_count($app, $anchor) !== 1) {
        fail('无法定位 config/app.php 的 AppServiceProvider；请使用 Laravel 10 的标准配置。');
    }
    $app = str_replace($anchor, $anchor."\n        ".$provider.',', $app);
}
$files['config/app.php'] = $app;

$api = file_get_contents($root.'/routes/api.php');
if (! preg_match('~(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*["\']/im\.php["\']~', $api)) {
    $api = preg_replace('/\?>\s*$/', '', $api);
    $api = rtrim($api)."\n\nrequire __DIR__.'/im.php';\n";
}
$files['routes/api.php'] = $api;

$web = file_get_contents($root.'/routes/web.php');
if (! preg_match('~Route::(?:get|view)\s*\(\s*["\']/im["\']~', $web)) {
    $web = preg_replace('/\?>\s*$/', '', $web);
    $web = rtrim($web)."\n\n".<<<'GIORGIO_IM_WEB_ROUTE'
Route::get('/im', function () {
    abort_unless(app()->environment('local', 'testing'), 404);

    return view('im');
})->middleware(\GiorgioSocket\Http\Middleware\RequireSecureTransport::class.':im');
GIORGIO_IM_WEB_ROUTE;
}
$files['routes/web.php'] = $web;

try {
    $manifest = json_decode(file_get_contents($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('package.json 无法解析：'.$exception->getMessage());
}
if (! is_array($manifest)) {
    fail('package.json 必须是 JSON 对象。');
}
$manifest['scripts']['build:im'] = 'vite build --config vite.im.config.ts';
$manifest['dependencies']['@m-finder/giorgio-im'] ??= '^0.1.2';
$manifest['dependencies']['vue'] ??= '^3.5.0';
$files['package.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$npmrc = is_file($root.'/.npmrc') ? file_get_contents($root.'/.npmrc') : '';
foreach ([
    '@m-finder:registry' => 'https://npm.pkg.github.com',
    '//npm.pkg.github.com/:_authToken' => '${GIORGIO_IM_NPM_TOKEN}',
] as $key => $value) {
    $pattern = '~^[ \t]*'.preg_quote($key, '~').'[ \t]*=.*$~m';
    $line = $key.'='.$value;
    if (preg_match($pattern, $npmrc)) {
        $npmrc = preg_replace_callback($pattern, fn () => $line, $npmrc);
    } else {
        $npmrc = ($npmrc === '' ? '' : rtrim($npmrc)."\n").$line."\n";
    }
}
$files['.npmrc'] = $npmrc;

$environment = is_file($root.'/.env')
    ? file_get_contents($root.'/.env')
    : (is_file($root.'/.env.example') ? file_get_contents($root.'/.env.example') : '');
$database = $root.'/storage/app/im-demo/database.sqlite';
$prefix = 'giorgio-im-demo:'.substr(hash('sha256', $root), 0, 12).':';
$settings = [
    'APP_ENV' => 'local',
    'APP_URL' => 'http://127.0.0.1:'.getenv('GIORGIO_IM_HTTP_PORT'),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => '"'.addcslashes($database, '\"$').'"',
    'DATABASE_URL' => '',
    'REDIS_CLIENT' => 'predis', 'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => getenv('GIORGIO_IM_REDIS_PORT'),
    'REDIS_USERNAME' => 'null', 'REDIS_PASSWORD' => 'null', 'REDIS_URL' => '',
    'REDIS_DB' => '0', 'REDIS_PREFIX' => $prefix,
    'CACHE_DRIVER' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync',
    'SOCKET_HOST' => '127.0.0.1', 'SOCKET_PORT' => getenv('GIORGIO_IM_SOCKET_PORT'),
    'SOCKET_DRIVER' => 'native', 'SOCKET_PREFIX' => $prefix.'socket:',
    'SOCKET_PUSH_URL' => 'http://127.0.0.1:'.getenv('GIORGIO_IM_SOCKET_PORT').'/push',
    'IM_SOCKET_URL' => 'ws://127.0.0.1:'.getenv('GIORGIO_IM_SOCKET_PORT'),
];
foreach (['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'SOCKET_PUSH_TOKEN' => bin2hex(random_bytes(32))] as $key => $value) {
    preg_match('/^'.preg_quote($key, '/').'[ \t]*=[ \t]*([^\r\n]*)$/m', $environment, $match);
    $existing = trim($match[1] ?? '');
    if (preg_match('/^(?:["\']\s*["\']|null)?(?:\s*#.*)?$/i', $existing)) {
        $settings[$key] = $value;
    }
}
foreach ($settings as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';
    if (preg_match($pattern, $environment)) {
        $environment = preg_replace_callback($pattern, fn () => $key.'='.$value, $environment);
    } else {
        $environment = rtrim($environment)."\n".$key.'='.$value."\n";
    }
}
$files['.env'] = $environment;

$changes = [];
foreach ($files as $path => $content) {
    $content = rtrim($content, "\r\n")."\n";
    $destination = $root.'/'.$path;
    if (is_link($destination)) {
        fail('请先处理目标软链接：'.$path);
    }
    if (is_file($destination) && file_get_contents($destination) === $content) {
        continue;
    }
    if (file_exists($destination) && ! is_file($destination)) {
        fail('目标不是普通文件：'.$path);
    }
    $changes[$path] = $content;
}

$backup = null;
foreach ($changes as $path => $content) {
    if (! is_file($root.'/'.$path)) {
        continue;
    }
    $backup ??= $root.'/storage/app/im-demo-backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
    $destination = $backup.'/'.$path;
    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
        fail('无法创建备份目录：'.dirname($destination));
    }
    if (! copy($root.'/'.$path, $destination) || ! chmod($destination, 0600)) {
        fail('无法备份：'.$path);
    }
}
foreach ($changes as $path => $content) {
    $destination = $root.'/'.$path;
    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true)) {
        fail('无法创建目录：'.dirname($destination));
    }
    if (file_put_contents($destination, $content) === false) {
        fail('无法写入：'.$path);
    }
    if ($path === '.env') {
        chmod($destination, 0600);
    }
    echo '写入 '.$path."\n";
}
if (! is_dir(dirname($database)) && ! mkdir(dirname($database), 0755, true)) {
    fail('无法创建 SQLite 目录。');
}
if (! file_exists($database) && ! touch($database)) {
    fail('无法创建 SQLite 数据库。');
}
echo $changes ? "演示文件与环境配置已写入。\n" : "文件已是最新，无需修改。\n";
if ($backup !== null) {
    echo '原文件备份：'.$backup."\n";
}
GIORGIO_IM_SETUP_PHP

composer require 'wu/giorgio-im-socket:^1.0.1' 'predis/predis:^2.3' 'laravel/sanctum:^3.3' --with-all-dependencies --prefer-dist --no-interaction
if [[ ! -f config/socket.php ]]; then
    php artisan vendor:publish --provider='GiorgioSocket\Providers\SocketServiceProvider' --tag=socket-config --no-interaction
fi
php artisan config:clear
php artisan route:clear
php artisan migrate --force

if ! GIORGIO_IM_NPM_TOKEN="$npm_token" npm install --fetch-timeout=30000 --fetch-retries=1; then
    unset npm_token
    printf '%s\n' 'npm 安装失败。若 GitHub Packages 返回 401/403，请确认 classic PAT 有 read:packages 权限且能访问该包，重新运行脚本并输入 token。' >&2
    exit 1
fi
unset npm_token
npm run build:im

printf '\n安装完成：%s\n' "$(pwd)"
printf '四个终端分别运行：\n'
printf "redis-server --bind 127.0.0.1 --port %s --save '' --appendonly no\n" "$GIORGIO_IM_REDIS_PORT"
printf 'php artisan socket:start\nphp artisan socket:events\n'
printf 'php artisan serve --host=127.0.0.1 --port=%s --no-reload\n' "$GIORGIO_IM_HTTP_PORT"
printf '页面：http://127.0.0.1:%s/im\n' "$GIORGIO_IM_HTTP_PORT"
