import { request } from '../api/client';

export async function ssoMutation(path, payload) {
  const security = await request('/sso/security');
  if (!security?.csrf_token) throw new Error('授权操作校验不可用');
  return request(path, {
    method: 'POST',
    headers: {
      'X-CSRF-Token': security.csrf_token,
      'X-Request-Token': crypto.randomUUID(),
    },
    body: JSON.stringify(payload),
  });
}

export function validateLaunch(data, favorite, issuer) {
  const target = new URL(data?.open_url || '');
  const registered = new URL(favorite?.url || '');
  if (target.protocol !== 'https:' || target.origin !== registered.origin
    || data?.target_origin !== registered.origin || data?.issuer !== issuer
    || target.search || !/^claim_token=[A-Za-z0-9_-]{16,}$/.test(target.hash.slice(1))) {
    throw new Error('授权转接地址校验失败');
  }
  return target.href;
}

export function ssoResume() {
  const value = new URLSearchParams(window.location.search).get('sso_resume') || '';
  return /^[A-Za-z0-9_-]{20,128}$/.test(value) ? value : '';
}

export function finishSsoResume() {
  const resume = ssoResume();
  if (resume) window.location.assign(`/api/oidc/resume?resume=${encodeURIComponent(resume)}`);
  return Boolean(resume);
}
