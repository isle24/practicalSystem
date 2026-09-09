import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const root = fileURLToPath(new URL('..', import.meta.url));

// 分别构建连接页和同一份 PC 源码。
for (const [directory, args] of [
  [root, []],
  [join(root, '../pc'), ['--mode', 'desktop', '--outDir', '../desktop/dist/pc']],
]) {
  const result = spawnSync(process.execPath, [join(directory, 'node_modules/vite/bin/vite.js'), 'build', ...args], {
    cwd: directory,
    stdio: 'inherit',
    env: {
      ...process.env,
      VITE_API_BASE: '/api',
      VITE_API_ORIGIN: '',
      VITE_API_PROXY: '',
    },
  });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status || 1);
}
