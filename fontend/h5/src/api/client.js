const apiBase = import.meta.env.VITE_API_BASE || '/api';

export async function request(path, options = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
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
    throw error;
  }

  return payload.data;
}
