export function projectFileTool({ writable, rpc, countTool }) {
  const properties = {
    action: { type: 'string', enum: writable ? ['list', 'read', 'write'] : ['list', 'read'] },
    path: { type: 'string', description: '项目内相对路径，用 / 分隔；列出根目录可省略。' },
    offset: { type: 'integer', minimum: 0, description: '目录条目、文本 UTF-8 字节或表格行的零起始偏移。优先使用结果中的 next_offset。' },
    sheet: { type: 'string', description: '读取 XLSX 指定工作表时填写完整表名。' },
  };
  if (writable) properties.content = { type: 'string', description: '要创建或替换的完整 UTF-8 文本，最多 32 KiB。' };
  return {
    name: 'project_file',
    label: '本机项目文件',
    description: '列出绑定项目目录、读取文本和表格。图片识别需要用户从聊天输入框添加附件。'
      + (writable ? '在现有目录中写入文本前，Agent 窗口向用户展示完整文件路径和新内容，用户仅可批准本次写入。' : ''),
    parameters: { type: 'object', properties, required: ['action'], additionalProperties: false },
    executionMode: 'sequential',
    async execute(_id, params, signal) {
      countTool();
      const result = await rpc('project_file', params, signal);
      return { content: [{ type: 'text', text: JSON.stringify(result) }], details: {} };
    },
  };
}
