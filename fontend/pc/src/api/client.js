import { createSessionCoordination, isCookieSessionRequest, isSessionMutation } from '../../../shared/sessionCoordination';

export const apiBase = import.meta.env.VITE_API_BASE || '/api';
let refreshPromise = null;
let sessionGeneration = 0;
let sessionChanging = false;
const sessionCoordination = createSessionCoordination(error => {
  invalidateSessionRequests();
  sessionChanging = true;
  emitAuthExpired(new Error(error ? '登录响应未完整收到，请重新加载页面' : '其他窗口已变更登录账号，请重新加载页面', { cause: error }));
  window.location.reload();
});

export function invalidateSessionRequests() {
  sessionGeneration++;
}

function cancelledRequest() {
  const error = new Error('操作已取消或账号上下文已变化');
  error.name = 'AbortError';
  return error;
}

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
  if (sessionChanging) throw cancelledRequest();
  if (isSessionMutation(path)) {
    const pendingRefresh = refreshPromise;
    sessionChanging = true;
    invalidateSessionRequests();
    const generation = sessionGeneration;
    try {
      if (pendingRefresh) await pendingRefresh;
      if (generation !== sessionGeneration) throw cancelledRequest();
      return await sendRequest(path, options, false);
    } finally {
      invalidateSessionRequests();
      sessionChanging = false;
    }
  }
  return sendRequest(path, options, true);
}

async function sendRequest(path, options = {}, canRefresh = true) {
  const generation = sessionGeneration;
  const {
    timeoutMs: rawTimeoutMs,
    refreshOnUnauthorized = true,
    isCurrent = () => true,
    signal: externalSignal,
    headers = {},
    ...fetchOptions
  } = options;
  const requireCurrent = () => {
    if (!sessionCoordination.check()) throw cancelledRequest();
    if (generation !== sessionGeneration || externalSignal?.aborted || !isCurrent()) throw cancelledRequest();
  };
  requireCurrent();
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
      if (externalSignal.aborted) abortExternal();
    }
  }

  let response;
  let payload;
  let uncertainMutation = false;
  try {
    const send = async () => {
      const mutation = isSessionMutation(path);
      let sent = false;
      try {
        if ((abortController?.signal || externalSignal)?.aborted) {
          const error = new Error('请求已取消');
          error.name = 'AbortError';
          throw error;
        }
        sent = true;
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
        payload = await response.json().catch(error => {
          if (error?.name === 'AbortError') throw error;
          if (mutation) throw new Error('接口响应格式错误', { cause: error });
          return { code: response.status, message: '接口响应格式错误', data: null };
        });
        if (mutation) {
          if (!payload || typeof payload !== 'object' || Array.isArray(payload) || !Number.isFinite(payload.code)) {
            throw new Error('接口响应格式错误');
          }
          if (payload.code === 0) {
            if (!response.ok) throw new Error('接口响应状态异常');
            sessionCoordination.notify();
          }
        }
      } catch (error) {
        if (mutation && sent) {
          uncertainMutation = true;
          sessionCoordination.notify();
          try { sessionCoordination.invalidateSelf(error); } catch {}
        }
        throw error;
      }
    };
    await sessionCoordination.run(send, requireCurrent, abortController?.signal || externalSignal, isCookieSessionRequest(path) ? 'exclusive' : 'shared');
  } catch (error) {
    if (uncertainMutation) throw error;
    requireCurrent();
    if (error?.name === 'AbortError') {
      const cancelled = new Error(didTimeout ? '请求超时，请稍后重试' : '请求已取消');
      cancelled.name = 'AbortError';
      throw cancelled;
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

  requireCurrent();

  if (payload.code === 40310) window.dispatchEvent(new CustomEvent('practical-wechat-required'));

  if (!response.ok || payload.code !== 0) {
    const error = new Error(payload.message || '请求失败');
    error.status = response.status;
    error.code = Number(payload.code || response.status || 0);
    error.apiPath = `${apiBase}${path}`;
    error.payload = payload;
    if (isAuthExpired(response, payload) && !isAuthPath(path)) {
      const refreshed = canRefresh && refreshOnUnauthorized ? await refreshSession() : false;
      requireCurrent();
      if (refreshed) {
        return sendRequest(path, options, false);
      }
      emitAuthExpired(error);
    }
    throw error;
  }

  return payload.data;
}

export async function waitForPendingRefresh() {
  const pending = refreshPromise;
  if (pending) await pending;
}

function isAuthExpired(response, payload) {
  return response.status === 401 || Number(payload?.code || 0) === 40100;
}

function isAuthPath(path) {
  return String(path || '').startsWith('/auth/password-recovery/')
    || String(path || '').startsWith('/auth/desktop-lock/')
    || String(path || '').startsWith('/auth/wechat-login/')
    || String(path || '').startsWith('/auth/login')
    || String(path || '').startsWith('/auth/register')
    || String(path || '').startsWith('/auth/register-options')
    || String(path || '').startsWith('/auth/logout')
    || String(path || '').startsWith('/auth/passkey-login')
    || String(path || '').startsWith('/auth/refresh');
}

async function refreshSession() {
  if (!refreshPromise) {
    const pending = sendRequest('/auth/refresh', { method: 'POST' }, false)
      .then(() => true)
      .catch(() => false)
      .finally(() => {
        if (refreshPromise === pending) refreshPromise = null;
      });
    refreshPromise = pending;
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
