const lockName = 'practical-cookie-session';
const revisionKey = 'practical:cookie-session-revision';
const channelName = 'practical-cookie-session';
const mutationPaths = new Set(['/auth/login', '/auth/register', '/auth/logout', '/auth/switch-account', '/auth/passkey-login', '/auth/wechat-login/consume', '/auth/password-recovery/reset']);
const pathname = path => String(path || '').split('?')[0];

export const isSessionMutation = path => mutationPaths.has(pathname(path));
export const isCookieSessionRequest = path => isSessionMutation(path) || ['/auth/refresh', '/auth/context'].includes(pathname(path));

export function createSessionCoordination(onChanged) {
  const enabled = () => typeof window !== 'undefined' && !window.__PRACTICAL_DESKTOP__;
  const readRevision = () => {
    if (!enabled()) return null;
    try { return window.localStorage.getItem(revisionKey) || ''; } catch { return null; }
  };
  let storageRevision = readRevision();
  let ownRevision = storageRevision;
  let invalidated = false;
  let channel = null;

  const changed = revision => {
    if (!enabled() || !revision || revision === ownRevision || invalidated) return;
    invalidated = true;
    onChanged();
  };
  const invalidateSelf = error => {
    if (!enabled() || invalidated) return;
    invalidated = true;
    onChanged(error);
  };
  const check = () => {
    const revision = readRevision();
    if (revision !== null && storageRevision !== null && revision !== storageRevision) changed(revision);
    return !invalidated;
  };

  if (enabled()) {
    window.addEventListener('storage', event => {
      if (event.key === revisionKey) changed(event.newValue);
    });
    window.addEventListener('pageshow', check);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    try {
      channel = new BroadcastChannel(channelName);
      channel.addEventListener('message', event => {
        if (typeof event.data === 'string' && event.data.length <= 160) changed(event.data);
      });
      window.addEventListener('pagehide', event => { if (!event.persisted) channel.close(); });
    } catch { channel = null; }
  }

  const notify = () => {
    if (!enabled()) return;
    const revision = `${Date.now()}:${Math.random().toString(36).slice(2)}`;
    ownRevision = revision;
    try {
      window.localStorage.setItem(revisionKey, revision);
      storageRevision = revision;
    } catch {}
    try { channel?.postMessage(revision); } catch {}
  };

  const run = (operation, requireCurrent, signal, mode = 'exclusive') => {
    const execute = () => {
      check();
      requireCurrent();
      return operation();
    };
    if (!enabled() || !navigator.locks?.request) return execute();
    return navigator.locks.request(lockName, { mode, ...(signal ? { signal } : {}) }, execute);
  };

  return { check, invalidateSelf, notify, run };
}
