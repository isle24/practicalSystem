import { onBeforeUnmount, ref, watch } from 'vue';

/** 单个登录会话的实时通知、重连和离线补取。 */
export function useRealtimeMessages({ sessionKey, request, backendOrigin, invalidate }) {
  const status = ref('offline');
  let socket, retryTimer, heartbeat, fallback, refreshTimer, generation = 0, attempt = 0, lastPong = 0;
  const topics = new Set();
  const refresh = (topic = 'messages') => {
    topics.add(topic);
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      const pending = [...topics];
      topics.clear();
      for (const item of pending) Promise.resolve().then(() => invalidate(item)).catch(() => {});
    }, 180);
  };
  function stop() {
    generation++;
    clearTimeout(retryTimer);
    clearTimeout(refreshTimer);
    topics.clear();
    clearInterval(heartbeat);
    clearInterval(fallback);
    if (socket) { socket.onclose = null; socket.close(); socket = null; }
    status.value = 'offline';
  }
  async function connect(version) {
    if (version !== generation) return;
    status.value = 'connecting';
    try {
      const data = await request('/message/realtime-ticket', { method: 'POST' });
      if (version !== generation) return;
      const origin = window.__PRACTICAL_DESKTOP__?.serverOrigin || backendOrigin || window.location.origin;
      const url = new URL(data.path, origin);
      // 本地直连 Webman；生产环境统一由同域 NGINX 转发。
      if (['127.0.0.1', 'localhost'].includes(url.hostname) && ['5173', '5174', '8787'].includes(url.port)) url.port = '8788';
      url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
      const connection = new WebSocket(url);
      socket = connection;
      connection.onopen = () => connection.send(JSON.stringify({ type: 'auth', ticket: data.ticket }));
      connection.onmessage = event => {
        if (version !== generation) return;
        let data;
        try { data = JSON.parse(event.data); } catch { return; }
        if (data.type === 'ready') {
          status.value = 'connected'; attempt = 0; lastPong = Date.now(); refresh();
          clearInterval(heartbeat);
          heartbeat = setInterval(() => {
            if (Date.now() - lastPong > 60000) { connection.close(); return; }
            if (connection.readyState === WebSocket.OPEN) connection.send('{"type":"ping"}');
          }, 25000);
        }
        if (data.type === 'pong') lastPong = Date.now();
        if (data.type === 'invalidate') refresh(data.topic);
      };
      connection.onerror = () => connection.close();
      connection.onclose = () => { clearInterval(heartbeat); reconnect(version); };
    } catch { reconnect(version); }
  }
  function reconnect(version) {
    if (version !== generation) return;
    status.value = 'offline';
    clearTimeout(retryTimer);
    retryTimer = setTimeout(() => connect(version), Math.min(30000, 1000 * 2 ** Math.min(attempt++, 5)) + Math.random() * 750);
  }
  const visible = () => { if (!document.hidden && sessionKey()) refresh(); };
  document.addEventListener('visibilitychange', visible);
  watch(sessionKey, value => {
    stop(); attempt = 0;
    if (!value) return;
    connect(generation);
    fallback = setInterval(() => { if (!document.hidden) refresh(); }, 60000);
  }, { immediate: true });
  onBeforeUnmount(() => { stop(); document.removeEventListener('visibilitychange', visible); });
  return { status };
}
