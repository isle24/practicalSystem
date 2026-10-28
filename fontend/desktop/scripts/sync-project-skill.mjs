import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const check = process.argv.includes('--check');
const skill = join(root, 'server/cli/skill/practical-system');
const desktop = join(root, 'fontend/desktop/agent/skills/practical-system');
const menusPath = join(root, 'fontend/shared/operation-guides.json');
const manifestPath = join(desktop, 'resources.json');
const hash = value => createHash('sha256').update(value).digest('hex');
const write = (path, value) => {
  if (check) {
    if (!existsSync(path) || !readFileSync(path).equals(Buffer.from(value))) throw Error(`Skill resource differs: ${path}`);
  } else { mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, value); }
};
const menus = JSON.parse(readFileSync(menusPath, 'utf8'));
if (!Array.isArray(menus) || !menus.length || new Set(menus.map(item => item.key)).size !== menus.length) throw Error('Invalid operation guide catalog');
const lines = ['# 菜单操作说明', '', '先按菜单名称或 key 定位说明；页面功能不等于 CLI 已开放能力。实际按钮、状态及权限以当前学校页面和实时能力目录为准。说明本身不授予权限。', '', '## 菜单索引', ''];
for (const item of menus) lines.push(`- ${item.title}：\`${item.key}\``);
for (const item of menus) {
  if (!item.key || !item.title || !item.purpose || !Array.isArray(item.steps) || !item.steps.length || item.steps.some(step => !step.action || !step.expected || !step.next)) throw Error(`Incomplete operation guide: ${item.key}`);
  lines.push('', `## ${item.title}`, '', `菜单 key：\`${item.key}\``, '', `用途：${item.purpose}`, '', '开始前：', ...item.prerequisites.map(value => `- ${value}`), '', '操作步骤：');
  item.steps.forEach((step, index) => lines.push(`${index + 1}. ${step.action} 完成标志：${step.expected} 下一步：${step.next}`));
  lines.push('', '完成后：', ...item.next.map(value => `- ${value}`), '', '遇到问题：', ...item.issues.map(value => `- ${value}`), '', item.capabilities.length ? `可查找的能力：${item.capabilities.map(value => `\`${value}\``).join('、')}。调用前逐项核对实时目录。` : '本菜单未列出可直接调用的业务能力；使用原页面完成操作，不构造未登记 API。');
}
const menuMarkdown = lines.join('\n') + '\n';
write(join(desktop, 'references/menu-guides.json'), readFileSync(menusPath));
const sourcesAvailable = existsSync(join(skill, 'references/workflows.md'));
if (!sourcesAvailable && !check) throw Error('CLI Skill source is required to generate resources');
if (sourcesAvailable) {
  write(join(skill, 'references/menu-guides.md'), menuMarkdown);
  const workflow = readFileSync(join(skill, 'references/workflows.md'));
  const intro = readFileSync(join(desktop, 'SKILL.md'));
  if (workflow.length + intro.length + 128 > 32768) throw Error('Built-in Skill exceeds 32 KiB context budget');
  write(join(desktop, 'references/workflows.md'), workflow);
  const metadata = { version: 1, files: Object.fromEntries(['SKILL.md', 'references/workflows.md', 'references/menu-guides.json'].map(path => [path, hash(readFileSync(join(desktop, path)))])) };
  write(manifestPath, JSON.stringify(metadata, null, 2) + '\n');
} else {
  const metadata = JSON.parse(readFileSync(manifestPath, 'utf8'));
  for (const path of ['SKILL.md', 'references/workflows.md', 'references/menu-guides.json']) {
    if (metadata.version !== 1 || metadata.files[path] !== hash(readFileSync(join(desktop, path)))) throw Error(`Invalid embedded Skill resource: ${path}`);
  }
  if (readFileSync(join(desktop, 'SKILL.md')).length + readFileSync(join(desktop, 'references/workflows.md')).length + 128 > 32768) throw Error('Built-in Skill exceeds 32 KiB context budget');
}
console.log(`Project Skill ${check ? 'verified' : 'synchronized'}: ${menus.length} menu guides`);
