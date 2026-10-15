import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('..', import.meta.url));
const temporary = mkdtempSync(join(tmpdir(), 'practical-app-icon-'));

try {
  const source = readFileSync(join(root, 'assets/app-icon.png')).toString('base64');
  const input = join(temporary, 'app-icon.svg');
  writeFileSync(input, `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1024" height="1024" viewBox="0 0 2048 2048">
  <defs><clipPath id="rounded"><rect x="100" y="100" width="1848" height="1848" rx="400"/></clipPath></defs>
  <image x="100" y="100" width="1848" height="1848" clip-path="url(#rounded)" xlink:href="data:image/png;base64,${source}"/>
  <rect x="102" y="102" width="1844" height="1844" rx="398" fill="none" stroke="#d4d7dd" stroke-width="4"/>
</svg>`);
  const result = spawnSync(process.execPath, [join(root, 'node_modules/@tauri-apps/cli/tauri.js'), 'icon', input, '--output', join(root, 'src-tauri/icons')], { cwd: root, stdio: 'inherit' });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`Icon generation failed: ${result.status}`);
} finally {
  rmSync(temporary, { recursive: true, force: true });
}
