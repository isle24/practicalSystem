export function createUsage(model, capacity) {
  const metric = () => ({ tokens: 0, reports: 0 });
  return { model, calls: 0, input: metric(), output: metric(), total: metric(), context_tokens: null, context_window: capacity };
}

export function recordUsage(usage, response) {
  const tokens = value => Number.isSafeInteger(value) && value >= 0 ? value : null;
  const input = tokens(response?.prompt_tokens);
  const output = tokens(response?.completion_tokens);
  const total = tokens(response?.total_tokens) ?? (input !== null && output !== null ? input + output : null);
  usage.calls++;
  for (const [key, value] of Object.entries({ input, output, total })) {
    if (value === null) continue;
    usage[key].tokens += value;
    usage[key].reports++;
  }
  usage.context_tokens = total ?? input;
  usage.context_input_only = total === null && input !== null;
  return { input, output, total };
}

export function goalProgressTool(countTool, onProgress) {
  return {
    name: 'goal_progress',
    label: '更新目标进度',
    description: '记录当前目标的完整步骤。按实际结果标记进度。全部步骤完成后标记 completed，需要用户补充信息或处理阻塞时标记 blocked 并填写原因。',
    parameters: {
      type: 'object', additionalProperties: false, required: ['status', 'steps'],
      properties: {
        status: { type: 'string', enum: ['active', 'completed', 'blocked'] },
        note: { type: 'string', maxLength: 400 },
        steps: { type: 'array', minItems: 1, maxItems: 16, items: {
          type: 'object', additionalProperties: false, required: ['title', 'status'],
          properties: { title: { type: 'string', minLength: 1, maxLength: 160 }, status: { type: 'string', enum: ['pending', 'active', 'done'] } },
        } },
      },
    },
    async execute(_id, progress) {
      countTool();
      if (progress.steps.some(step => !step.title.trim())
        || progress.status === 'completed' && progress.steps.some(step => step.status !== 'done')
        || progress.status === 'blocked' && !progress.note?.trim()) {
        return { content: [{ type: 'text', text: '请填写步骤名称，完成目标前逐项确认结果，阻塞时填写具体原因。' }], details: {}, isError: true };
      }
      onProgress(progress);
      return { content: [{ type: 'text', text: '目标进度已记录。' }], details: {} };
    },
  };
}
