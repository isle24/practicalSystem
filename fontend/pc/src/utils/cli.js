import { onBeforeUnmount, watch } from 'vue';
import { request } from '../api/client';

export function useCliScope(sessionKey) {
  let alive = true;
  let generation = 0;
  const controllers = new Set();
  function invalidate() {
    generation++;
    controllers.forEach(controller => controller.abort());
    controllers.clear();
  }
  watch(sessionKey, invalidate, { flush: 'sync' });
  onBeforeUnmount(() => { alive = false; invalidate(); });
  return () => {
    const key = sessionKey();
    const version = generation;
    const controller = new AbortController();
    controllers.add(controller);
    const current = () => alive && Boolean(key) && key === sessionKey() && version === generation && !controller.signal.aborted;
    return {
      current,
      options: { signal: controller.signal, isCurrent: current },
      finish: () => controllers.delete(controller),
    };
  };
}

export async function cliMutation(path, payload, options = {}) {
  const { accountSession = false, ...requestOptions } = options;
  const security = await request(accountSession ? '/cli/operations/security' : '/cli/security', requestOptions);
  if (requestOptions.isCurrent && !requestOptions.isCurrent()) {
    const error = new Error('登录上下文已变化，请重新读取');
    error.name = 'AbortError';
    throw error;
  }
  if (!security?.csrf_token) throw new Error('CLI 操作校验不可用，请重新读取');
  if (!crypto.randomUUID) throw new Error('当前连接不支持安全授权，请使用学校 HTTPS 地址');
  return request(path, {
    ...requestOptions,
    method: 'POST',
    headers: { 'X-CSRF-Token': security.csrf_token, 'X-Request-Token': crypto.randomUUID() },
    body: JSON.stringify(payload),
  });
}

export function cliError(error) {
  const reason = error?.payload?.data?.error || '';
  const messages = {
    expired_token: '授权码已过期，请在 CLI 中重新发起登录。',
    expired_code: '授权码已过期，请在 CLI 中重新发起登录。',
    invalid_user_code: '授权码无效，请核对 CLI 中显示的地址。',
    invalid_code: '授权码无效，请核对 CLI 中显示的地址。',
    access_denied: '本次授权已被拒绝，请在 CLI 中重新发起登录。',
    cli_disabled: '学校尚未启用 CLI，请联系学校管理员。',
    policy_disabled: '学校尚未启用 CLI，请联系学校管理员。',
    cli_not_enabled: '学校尚未启用 CLI，请联系学校管理员。',
    no_capabilities: '当前账号没有可授权的 CLI 功能，请联系学校管理员。',
    revision_conflict: '学校策略已由其他管理员更新，请重新读取后修改。',
    policy_revision_conflict: '学校策略已由其他管理员更新，请重新读取后修改。',
  };
  if (messages[reason]) return messages[reason];
  if (error?.status === 409) return '状态已变化，请重新读取后操作。';
  if (error?.status === 403) return error.message || '当前账号无权执行此操作。';
  if (error?.status === 404) return 'CLI 功能或授权码不可用，请确认学校已完成部署。';
  if (navigator.onLine === false || error instanceof TypeError) return '无法连接学校服务器，请检查网络后重试。';
  return error?.message || 'CLI 请求失败，请稍后重试。';
}

export function cliTimestamp(value) {
  if (typeof value === 'number' || /^\d{10,13}$/.test(String(value || ''))) {
    const number = Number(value);
    return number < 1e12 ? number * 1000 : number;
  }
  return Date.parse(String(value || ''));
}

export function cliTime(value) {
  const timestamp = cliTimestamp(value);
  return Number.isFinite(timestamp) ? new Date(timestamp).toLocaleString('zh-CN', { hour12: false }) : (value || '—');
}

export function cliDownloadUrl(value, publicOrigin) {
  if (typeof value !== 'string' || /[\s'"`$\\]/.test(value)) return '';
  try {
    const url = new URL(value, publicOrigin);
    const origin = new URL(publicOrigin);
    if (url.origin !== origin.origin || url.username || url.password || url.search || url.hash || !url.pathname.startsWith('/cli/')) return '';
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))) return '';
    return url.href;
  } catch { return ''; }
}
