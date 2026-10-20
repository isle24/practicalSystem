import { McpClient, StdioTransport, StreamableHttpTransport } from '@earendil-works/pi-mcp';
import { createHash } from 'node:crypto';

const connections = new Set();
const closing = new Map();

function closeClient(client) {
  if (closing.has(client)) return closing.get(client);
  const task = Promise.resolve().then(() => client.close()).catch(() => {});
  closing.set(client, task);
  void task.finally(() => closing.delete(client));
  return task;
}

async function connect(plugin, signal) {
  const client = new McpClient({ name: 'practical-desktop-agent', version: '0.3.31', requestTimeoutMs: 20_000 });
  const transport = plugin.transport === 'stdio'
    ? new StdioTransport({ command: plugin.command, args: plugin.args ?? [], env: plugin.env ?? {}, inheritEnv: false, stderr: 'pipe', maxMessageBytes: 512 * 1024, maxStderrBytes: 4096, closeTimeoutMs: 500 })
    : new StreamableHttpTransport({
      url: plugin.url, headers: plugin.token ? { Authorization: `Bearer ${plugin.token}` } : {},
      maxMessageBytes: 512 * 1024, openGetStream: false, reconnect: { maxRetries: 0 },
      fetch: (url, init) => {
        if (new URL(url).origin !== new URL(plugin.url).origin) throw new Error('MCP origin changed');
        return fetch(url, { ...init, redirect: 'error', credentials: 'omit', signal: AbortSignal.any([signal, ...(init?.signal ? [init.signal] : [])]) });
      },
    });
  connections.add(client);
  const abort = () => { void closeClient(client); };
  signal.addEventListener('abort', abort, { once: true });
  client.onClose(() => { connections.delete(client); signal.removeEventListener('abort', abort); });
  try { await client.connect(transport); return client; }
  catch { await closeClient(client); throw new Error('MCP 连接失败，请检查服务地址、凭据和本机环境。'); }
}

export async function closeMcp() {
  const tasks = [...connections].map(closeClient);
  await Promise.allSettled([...tasks, ...closing.values()]);
  connections.clear();
}

export async function probeMcp(plugin, signal) {
  const client = await connect(plugin, signal);
  try {
    const tools = await client.listTools({ signal, timeoutMs: 20_000 });
    if (tools.length > 128) throw new Error('MCP 工具数量超过 128。');
    return { ok: true, tools: tools.map((tool) => ({ name: String(tool.name).slice(0, 128), description: String(tool.description ?? '').slice(0, 1000) })), message: `已通过本机发现 ${tools.length} 个 MCP 工具` };
  } finally { await closeClient(client); }
}

export async function mcpTools(plugins, rpc, countTool, signal, maxTools = 32, sanitize = (value) => value) {
  if (!Array.isArray(plugins) || plugins.length > 20) throw new Error('MCP 插件配置数量无效。');
  const tools = [];
  for (const plugin of plugins) {
    if (plugin.type !== 'mcp' || plugin.enabled !== true) continue;
    const client = await connect(plugin, signal);
    const definitions = await client.listTools({ signal, timeoutMs: 20_000 });
    for (const definition of definitions) {
      if (tools.length >= maxTools || typeof definition.name !== 'string' || !definition.inputSchema || typeof definition.inputSchema !== 'object') throw new Error('MCP 工具定义无效或数量超过限制。');
      const name = `mcp_${createHash('sha256').update(`${plugin.id}\0${definition.name}`).digest('hex').slice(0, 32)}`;
      tools.push({
        name, label: `${plugin.name} · ${definition.title ?? definition.name}`,
        description: sanitize(String(definition.description ?? definition.name).slice(0, 3000)),
        parameters: sanitize({ ...definition.inputSchema, type: 'object', properties: definition.inputSchema.properties ?? {} }),
        executionMode: 'sequential',
        async execute(_id, params, callSignal) {
          countTool();
          const permitted = sanitize(params);
          if (JSON.stringify(permitted) !== JSON.stringify(params)) throw new Error('MCP 参数包含受保护凭据，未执行。');
          const approved = await rpc('confirm_mcp', { plugin_id: plugin.id, tool: definition.name, arguments: permitted }, callSignal);
          if (approved?.approved !== true) throw new Error('用户未批准 MCP 调用。');
          try {
            const result = await client.callTool(definition.name, permitted, { signal: callSignal, timeoutMs: 25_000 });
            const content = (result.content ?? []).filter((item) => item.type === 'text' && typeof item.text === 'string').map((item) => ({ type: 'text', text: item.text }));
            if (!content.length && result.structuredContent != null) content.push({ type: 'text', text: JSON.stringify(result.structuredContent) });
            if (Buffer.byteLength(JSON.stringify(content)) > 256 * 1024) throw new Error('MCP 结果超过大小限制。');
            return { content: sanitize(content.length ? content : [{ type: 'text', text: 'MCP 工具返回了非文本结果。' }]), details: {}, isError: result.isError === true };
          } catch { throw new Error('MCP 工具调用未完成；写入结果请核对后再继续。'); }
        },
      });
    }
  }
  return tools;
}
