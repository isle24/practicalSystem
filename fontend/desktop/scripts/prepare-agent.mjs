import { build } from 'esbuild';
import { builtinModules } from 'node:module';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile, chmod, copyFile, rename, rm, mkdtemp, readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { homedir, tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const nodeVersion = '22.23.3';
const distributions = {
  'aarch64-apple-darwin': { archive: `node-v${nodeVersion}-darwin-arm64.tar.gz`, sha256: '23b25245dcfb9af7262f8ff142e9e2e0af025368117329e7a7458a51e5922f53', directory: `node-v${nodeVersion}-darwin-arm64`, executable: 'bin/node' },
  'x86_64-apple-darwin': { archive: `node-v${nodeVersion}-darwin-x64.tar.gz`, sha256: '8a677b0219178efd6eb0e475457c4afb452b521a92f6e67845a73bd85727f2a8', directory: `node-v${nodeVersion}-darwin-x64`, executable: 'bin/node' },
  'x86_64-unknown-linux-gnu': { archive: `node-v${nodeVersion}-linux-x64.tar.gz`, sha256: '1084aa36196bba4c3a5e69a1ee388a6e4ff729dad09445fbcd434b28fe3c24af', directory: `node-v${nodeVersion}-linux-x64`, executable: 'bin/node' },
  'x86_64-pc-windows-msvc': { archive: `node-v${nodeVersion}-win-x64.zip`, sha256: '2b0ff57b049cda1bbcea2240eec20467018713c1efe1f7360c2681859b90ed71', directory: `node-v${nodeVersion}-win-x64`, executable: 'node.exe' },
};
const hostTarget = {
  'darwin-arm64': 'aarch64-apple-darwin',
  'darwin-x64': 'x86_64-apple-darwin',
  'linux-x64': 'x86_64-unknown-linux-gnu',
  'win32-x64': 'x86_64-pc-windows-msvc',
}[`${process.platform}-${process.arch}`];
const targetIndex = process.argv.indexOf('--target');
const target = targetIndex !== -1 ? process.argv[targetIndex + 1] : process.env.TAURI_ENV_TARGET_TRIPLE || process.env.CARGO_BUILD_TARGET || hostTarget;
const runtimeDirectory = join(root, 'src-tauri/agent-runtime');
const binaryDirectory = join(root, 'src-tauri/binaries');
const cacheDirectory = join(homedir(), '.cache/practical-desktop-agent', nodeVersion);
const digest = (buffer) => createHash('sha256').update(buffer).digest('hex');

function command(binary, args) {
  const result = spawnSync(binary, args, { stdio: 'inherit', windowsHide: true });
  if (result.error || result.status !== 0) throw new Error(`Agent preparation command failed: ${binary}`);
}

async function officialArchive(distribution) {
  const destination = join(cacheDirectory, distribution.archive);
  if (existsSync(destination)) {
    const cached = await readFile(destination);
    if (digest(cached) === distribution.sha256) return destination;
    await rm(destination);
  }
  console.log(`Downloading verified Node runtime: ${distribution.archive}`);
  const response = await fetch(`https://nodejs.org/dist/v${nodeVersion}/${distribution.archive}`, { redirect: 'error', signal: AbortSignal.timeout(600_000) });
  if (!response.ok || !response.body) throw new Error('Official Node runtime download failed');
  const chunks = [];
  let size = 0;
  let progress = 0;
  for await (const chunk of response.body) {
    size += chunk.byteLength;
    if (size > 100 * 1024 * 1024) throw new Error('Official Node runtime archive exceeds size limit');
    chunks.push(Buffer.from(chunk));
    if (size - progress >= 8 * 1024 * 1024) {
      progress = size;
      console.log(`Node runtime download: ${Math.floor(size / (1024 * 1024))} MiB`);
    }
  }
  const buffer = Buffer.concat(chunks);
  if (digest(buffer) !== distribution.sha256) throw new Error('Official Node runtime SHA256 verification failed');
  const temporary = `${destination}.${randomUUID()}.tmp`;
  await writeFile(temporary, buffer, { flag: 'wx' });
  await rename(temporary, destination);
  return destination;
}

async function prepareBinary(triple) {
  const distribution = distributions[triple];
  if (!distribution) throw new Error(`Unsupported Agent runtime target: ${triple}`);
  const archive = await officialArchive(distribution);
  const temporary = await mkdtemp(join(tmpdir(), 'practical-agent-node-'));
  try {
    command('tar', ['-xf', archive, '-C', temporary, `${distribution.directory}/${distribution.executable}`, `${distribution.directory}/LICENSE`]);
    const suffix = triple.includes('windows') ? '.exe' : '';
    const destination = join(binaryDirectory, `agent-node-${triple}${suffix}`);
    const staged = `${destination}.${randomUUID()}.tmp`;
    await copyFile(join(temporary, distribution.directory, distribution.executable), staged);
    await chmod(staged, 0o755);
    await rename(staged, destination);
    await copyFile(join(temporary, distribution.directory, 'LICENSE'), join(runtimeDirectory, 'NODE-LICENSE.txt'));
    return destination;
  } finally { await rm(temporary, { recursive: true, force: true }); }
}

if (!target || (!distributions[target] && target !== 'universal-apple-darwin')) throw new Error('Unsupported Agent runtime build target');
await mkdir(runtimeDirectory, { recursive: true });
await mkdir(binaryDirectory, { recursive: true });
await mkdir(cacheDirectory, { recursive: true });

const result = await build({
  absWorkingDir: root,
  entryPoints: [join(root, 'agent/runtime.mjs')],
  outfile: join(runtimeDirectory, 'runtime.mjs'),
  bundle: true,
  platform: 'node',
  format: 'esm',
  banner: { js: "import { createRequire as __practicalCreateRequire } from 'node:module';const require=__practicalCreateRequire(import.meta.url);" },
  target: 'node22.19',
  packages: 'bundle',
  minify: true,
  sourcemap: false,
  legalComments: 'eof',
  metafile: true,
});
for (const output of Object.values(result.metafile.outputs)) {
  if (output.imports.some((entry) => entry.external && !entry.path.startsWith('node:') && !builtinModules.includes(entry.path))) throw new Error('Agent runtime contains an unbundled dependency');
}

const bundledPackages = new Set();
for (const input of Object.keys(result.metafile.inputs)) {
  const match = input.replaceAll('\\', '/').match(/(?:^|\/)node_modules\/((?:@[^/]+\/)?[^/]+)/);
  if (match) bundledPackages.add(match[1]);
}
const notices = ['Practical System Desktop Agent runtime third-party notices'];
for (const packageName of [...bundledPackages].sort()) {
  const directory = join(root, 'node_modules', packageName);
  const metadata = JSON.parse(await readFile(join(directory, 'package.json'), 'utf8'));
  const files = (await readdir(directory)).filter((file) => /^(license|licence|copying|notice)(\.|$)/i.test(file));
  notices.push(`\n${packageName}@${metadata.version} (${metadata.license ?? 'see notice'})`);
  if (files.length) {
    for (const file of files.sort()) notices.push(`\n${file}\n${await readFile(join(directory, file), 'utf8')}`);
  } else if (['@earendil-works/pi-agent-core', '@earendil-works/pi-ai', '@earendil-works/pi-mcp'].includes(packageName)) {
    notices.push(`\nhttps://github.com/earendil-works/pi/blob/v1.3.0/LICENSE\n${await readFile(join(root, 'agent/licenses/pi-LICENSE.txt'), 'utf8')}`);
    if (packageName === '@earendil-works/pi-mcp') notices.push(await readFile(join(directory, 'LICENSES/modelcontextprotocol-typescript-sdk.txt'), 'utf8'));
  } else throw new Error(`Bundled dependency has no license notice: ${packageName}`);
}
await writeFile(join(runtimeDirectory, 'THIRD-PARTY-NOTICES.txt'), `${notices.join('\n')}\n`);

if (target === 'universal-apple-darwin') {
  if (process.platform !== 'darwin') throw new Error('Universal Agent runtime requires macOS lipo');
  const arm64 = await prepareBinary('aarch64-apple-darwin');
  const x64 = await prepareBinary('x86_64-apple-darwin');
  const universal = join(binaryDirectory, 'agent-node-universal-apple-darwin');
  const staged = `${universal}.${randomUUID()}.tmp`;
  command('lipo', ['-create', arm64, x64, '-output', staged]);
  command('lipo', [staged, '-verify_arch', 'arm64']);
  command('lipo', [staged, '-verify_arch', 'x86_64']);
  await chmod(staged, 0o755);
  await rename(staged, universal);
} else await prepareBinary(target);

console.log(`Agent runtime prepared: Pi 1.3.0, Node ${nodeVersion}, ${target}`);
