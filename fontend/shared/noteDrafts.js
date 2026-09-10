const active = new Set();

/** 每个编辑窗口独占一份本机草稿。 */
export function claimNoteDraft(sessionKey) {
  const prefix = `personal-note-draft:${sessionKey}:`;
  const key = prefix + crypto.randomUUID();
  let sourceKey = null, sourceValue = null, draft = null, accepted = false;
  try {
    for (const candidate of Object.keys(localStorage).filter(item => item.startsWith(prefix))) {
      if (active.has(candidate)) continue;
      const raw = localStorage.getItem(candidate), value = JSON.parse(raw);
      if (value?.item) { sourceKey = candidate; sourceValue = raw; draft = value; break; }
    }
  } catch { /* 本机存储不可用时仍支持服务器保存。 */ }
  active.add(key);
  if (sourceKey) active.add(sourceKey);
  return {
    key, draft,
    accept: () => { accepted = true; },
    clear: () => {
      localStorage.removeItem(key);
      if (accepted && sourceKey && localStorage.getItem(sourceKey) === sourceValue) localStorage.removeItem(sourceKey);
    },
    release: () => { active.delete(key); if (sourceKey) active.delete(sourceKey); },
  };
}
