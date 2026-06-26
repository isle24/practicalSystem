const apiBase = import.meta.env.VITE_API_BASE || '/api';
let refreshPromise = null;

export async function request(path, options = {}) {
  return sendRequest(path, options, true);
}

async function sendRequest(path, options = {}, canRefresh = true) {
  const isFormData = options.body instanceof FormData;
  const response = await fetch(`${apiBase}${path}`, {
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    ...options,
  });

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
