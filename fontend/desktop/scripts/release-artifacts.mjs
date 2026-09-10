import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync, copyFileSync } from 'node:fs';
import { join, basename } from 'node:path';
import { createHash } from 'node:crypto';

const root = 'fontend/desktop';
const directory = 'desktop-artifacts';
const config = JSON.parse(readFileSync(`${root}/src-tauri/tauri.conf.json`));
const version = config.version;
const sha = path => createHash('sha256').update(readFileSync(path)).digest('hex');
const scan = path => readdirSync(path).flatMap(name => { const file = join(path, name); return statSync(file).isDirectory() ? scan(file) : [file]; });
const packageFile = name => /\.(dmg|app\.tar\.gz|exe|AppImage|deb)(\.sig)?$/.test(name);

/** 从当前平台的最终 bundle 收集安装文件。 */
function collect(platform) {
  const bundle = `${root}/src-tauri/target/${platform === 'macos' ? 'universal-apple-darwin/' : ''}release/bundle`;
  mkdirSync(directory, { recursive: true });
  const files = scan(bundle).filter(file => packageFile(basename(file)));
  if (!files.length) throw Error('No installation artifacts found');
  const names = new Set();
  for (const file of files) {
    const name = basename(file).replace(/[^A-Za-z0-9._-]+/g, '-');
    if (names.has(name)) throw Error('Duplicate installation artifact: ' + name);
    names.add(name);
    copyFileSync(file, join(directory, name));
  }
  console.log(`Collected ${platform}: ${files.length} artifacts`);
}

/** 校验平台齐全和升级签名，生成不含服务器代码与 SQL 的清单。 */
function manifest() {
  if (!/^\d+\.\d+\.\d+$/.test(version)) throw Error('A stable semantic version is required');
  if (process.env.GITHUB_REF?.startsWith('refs/tags/') && process.env.GITHUB_REF !== `refs/tags/v${version}`) throw Error('Tag and package version differ');
  const files = scan(directory).filter(file => packageFile(file) && !file.endsWith('.sig'));
  const notes = readFileSync(`${root}/releases/${version}.md`, 'utf8').trim();
  const assets = files.map(file => {
    const name = basename(file);
    const platform = /\.(dmg|app\.tar\.gz)$/.test(name) ? 'darwin-universal' : name.endsWith('.exe') ? 'windows-x86_64' : 'linux-x86_64';
    const kind = /\.(app\.tar\.gz|exe|AppImage)$/.test(name) ? 'updater' : 'installer';
    const signature = kind === 'updater' ? readFileSync(file + '.sig', 'utf8').trim() : null;
    if (signature && !Buffer.from(signature, 'base64').toString().includes('untrusted comment:')) throw Error('Invalid updater signature: ' + name);
    return { file_name: name, platform, kind, size: statSync(file).size, sha256: sha(file), signature };
  });
  for (const platform of ['darwin-universal', 'windows-x86_64', 'linux-x86_64']) {
    if (assets.filter(a => a.platform === platform && a.kind === 'updater' && a.signature).length !== 1) throw Error('Missing or duplicate updater: ' + platform);
  }
  if (!assets.some(a => a.file_name.endsWith('.deb')) || !assets.some(a => a.file_name.endsWith('.dmg'))) throw Error('Missing manual installers');
  writeFileSync(join(directory, 'desktop-release.json'), JSON.stringify({ version, notes, source_commit: process.env.GITHUB_SHA, assets }, null, 2) + '\n');
  writeFileSync(join(directory, 'SHA256SUMS.txt'), scan(directory).filter(f => basename(f) !== 'SHA256SUMS.txt').map(f => `${sha(f)}  ${basename(f)}`).join('\n') + '\n');
  console.log(`Validated v${version}: ${assets.length} packages`);
}

/** 全部平台成功后上传草稿，资产完整才正式发布。 */
async function publish() {
  const repo = process.env.GITHUB_REPOSITORY;
  const token = process.env.GITHUB_TOKEN;
  if (!repo || !token) throw Error('GitHub Actions credentials are required');
  const api = `https://api.github.com/repos/${repo}`;
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28' };
  const call = async (url, options = {}) => { const r = await fetch(url, { ...options, headers: { ...headers, ...options.headers } }); if (!r.ok) throw Error(`GitHub HTTP ${r.status}`); return r.json(); };
  const data = JSON.parse(readFileSync(join(directory, 'desktop-release.json')));
  const releases = await call(`${api}/releases?per_page=100`);
  let release = releases.find(r => r.tag_name === `v${version}`);
  if (release && !release.draft) throw Error('This version is already published; bump the version to publish changed packages');
  if (!release) release = await call(`${api}/releases`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tag_name: `v${version}`, target_commitish: process.env.GITHUB_SHA, name: `实践管理系统客户端 v${version}`, body: data.notes, draft: true, prerelease: false }) });
  const oldAssets = await call(`${api}/releases/${release.id}/assets?per_page=100`);
  const files = scan(directory);
  for (const file of files) {
    const name = basename(file), bytes = readFileSync(file), old = oldAssets.find(a => a.name === name);
    if (old) { if (old.digest !== `sha256:${sha(file)}`) throw Error('Draft asset differs: ' + name); continue; }
    await call(release.upload_url.replace('{?name,label}', '') + '?name=' + encodeURIComponent(name), { method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: bytes });
  }
  const uploadedAssets = await call(`${api}/releases/${release.id}/assets?per_page=100`);
  if (uploadedAssets.length !== files.length) throw Error('Uploaded asset count differs from the release manifest');
  for (const file of files) {
    const name = basename(file), asset = uploadedAssets.find(a => a.name === name);
    if (!asset || asset.state !== 'uploaded' || asset.size !== statSync(file).size || asset.digest !== `sha256:${sha(file)}`) throw Error('Uploaded asset verification failed: ' + name);
  }
  await call(`${api}/releases/${release.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ draft: false, make_latest: 'true', body: data.notes }) });
  console.log(release.html_url);
}

const [command, platform] = process.argv.slice(2);
if (command === 'collect') collect(platform);
else if (command === 'manifest') manifest();
else if (command === 'publish') await publish();
else throw Error('Use collect, manifest, or publish');
