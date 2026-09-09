export const apiBase = import.meta.env.VITE_API_BASE || '/api';
let refreshPromise = null;

export function backendUrl(path = '') {
  const value = String(path || '').trim();
  if (!value) {
    return '';
  }
  if (/^https?:\/\//i.test(value)) {
    const desktop = window.__PRACTICAL_DESKTOP__;
    if (desktop && new URL(value).origin === desktop.serverOrigin) {
      const url = new URL(value);
      return new URL(`${url.pathname}${url.search}${url.hash}`, desktop.localOrigin).href;
    }
    return value;
  }

  const base = import.meta.env.VITE_API_ORIGIN
    || import.meta.env.VITE_API_PROXY
    || (/^https?:\/\//i.test(apiBase) ? apiBase : '')
    || (import.meta.env.DEV ? 'http://127.0.0.1:8787' : window.location.origin);
  return new URL(value.startsWith('/') ? value : `/${value}`, base).href;
}

// 对外分享链接使用学校服务器地址。
export function frontendPublicUrl() {
  return `${window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin}${window.location.pathname}`;
}

export async function request(path, options = {}) {
  return sendRequest(path, options, true);
}

async function sendRequest(path, options = {}, canRefresh = true) {
  const {
    timeoutMs: rawTimeoutMs,
    signal: externalSignal,
    headers = {},
    ...fetchOptions
  } = options;
  const isFormData = fetchOptions.body instanceof FormData;
  const timeoutMs = Number(rawTimeoutMs ?? (isFormData ? 120000 : 30000));
  let didTimeout = false;
  let timeoutId = null;
  let abortController = null;
  let abortExternal = null;

  if (timeoutMs > 0 && typeof AbortController !== 'undefined') {
    abortController = new AbortController();
    timeoutId = window.setTimeout(() => {
      didTimeout = true;
      abortController.abort();
    }, timeoutMs);
    if (externalSignal) {
      abortExternal = () => abortController.abort();
      externalSignal.addEventListener('abort', abortExternal, { once: true });
    }
  }

  let response;
  try {
    response = await fetch(`${apiBase}${path}`, {
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      ...fetchOptions,
      signal: abortController?.signal || externalSignal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new Error(didTimeout ? '请求超时，请稍后重试' : '请求已取消');
    }
    throw error;
  } finally {
    if (timeoutId) {
      window.clearTimeout(timeoutId);
    }
    if (externalSignal && abortExternal) {
      externalSignal.removeEventListener('abort', abortExternal);
    }
  }

  const payload = await response.json().catch(() => ({
    code: response.status,
    message: '接口响应格式错误',
    data: null,
  }));

  if (!response.ok || payload.code !== 0) {
    const error = new Error(payload.message || '请求失败');
    error.status = response.status;
    error.payload = payload;
    if (canRefresh && isAuthExpired(response, payload) && !isAuthPath(path)) {
      const refreshed = await refreshSession();
      if (refreshed) {
        return sendRequest(path, options, false);
      }
      emitAuthExpired(error);
    }
    throw error;
  }

  return payload.data;
}

function isAuthExpired(response, payload) {
  return response.status === 401 || Number(payload?.code || 0) === 40100;
}

function isAuthPath(path) {
  return String(path || '').startsWith('/auth/login')
    || String(path || '').startsWith('/auth/register')
    || String(path || '').startsWith('/auth/register-options')
    || String(path || '').startsWith('/auth/logout')
    || String(path || '').startsWith('/auth/passkey-login')
    || String(path || '').startsWith('/auth/refresh');
}

async function refreshSession() {
  if (!refreshPromise) {
    refreshPromise = sendRequest('/auth/refresh', { method: 'POST' }, false)
      .then(() => true)
      .catch(() => false)
      .finally(() => {
        refreshPromise = null;
      });
  }

  return refreshPromise;
}

function emitAuthExpired(error) {
  if (typeof window === 'undefined') {
    return;
  }
  window.dispatchEvent(new CustomEvent('practical-auth-expired', {
    detail: {
      message: error.message || '登录已过期，请重新登录',
    },
  }));
}
