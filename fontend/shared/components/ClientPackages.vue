<script setup>
import { computed, ref } from 'vue';
import { Download } from '@lucide/vue';

const props = defineProps({
  items: { type: Array, required: true },
  backendUrl: { type: Function, default: path => path },
  emptyText: { type: String, default: '学校尚未发布可下载的客户端。' },
});

const platforms = {
  windows: 'Windows',
  macos: 'macOS',
  linux: 'Linux',
  harmony: '鸿蒙',
  all: '其他',
};
const systems = { 'windows-x86_64': 'windows', 'darwin-universal': 'macos', 'linux-x86_64': 'linux' };

function currentSystem() {
  const { userAgent, userAgentData, platform, maxTouchPoints } = navigator;
  if (/HarmonyOS|OpenHarmony/i.test(userAgent)) return 'harmony';
  if (/Android|iPhone|iPad|iPod|CrOS/i.test(userAgent) || (platform === 'MacIntel' && maxTouchPoints > 1)) return '';
  const identity = userAgentData?.platform || platform || userAgent;
  if (/\bWin(?:dows|32|64)\b/i.test(identity)) return 'windows';
  if (/Mac/i.test(identity)) return 'macos';
  if (/Linux|X11/i.test(identity)) return 'linux';
  return '';
}

function describe(item) {
  const system = systems[item.platform];
  const name = item.file_name;
  if (system === 'macos') {
    if (/[-_](?:macOS-)?(?:aarch64|arm64)\.dmg$/i.test(name)) {
      return { system, title: 'macOS · Apple 芯片（ARM）', detail: '适用于 M 系列芯片的 Mac', rank: 0 };
    }
    if (/[-_](?:macOS-)?(?:intel|x64|x86_64)\.dmg$/i.test(name)) {
      return { system, title: 'macOS · Intel 芯片', detail: '适用于 Intel 芯片的 Mac', rank: 1 };
    }
    if (/[-_]universal\.dmg$/i.test(name)) {
      return { system, title: 'macOS · 通用版', detail: 'Apple 芯片和 Intel 芯片均可安装', rank: 2 };
    }
  }
  if (system === 'windows') return { system, title: 'Windows · 64 位（x64）', detail: 'EXE 安装程序', rank: 0 };
  if (system === 'linux' && /\.deb$/i.test(name)) {
    return { system, title: 'Linux · DEB（x64）', detail: '适用于 Debian、Ubuntu 等发行版', rank: 0 };
  }
  if (system === 'linux' && /\.AppImage$/i.test(name)) {
    return { system, title: 'Linux · AppImage（x64）', detail: '可执行应用文件，需要授予执行权限', rank: 1 };
  }
  return { system, title: platforms[system] || name, detail: name, rank: 3 };
}

const detected = currentSystem();
const selected = ref(detected);
const desktopOrder = ['windows', 'macos', 'linux'];
const order = [detected, ...desktopOrder.filter(system => system !== detected)];
const packages = computed(() => props.items.map(item => ({ ...item, ...describe(item) })));
const visiblePackages = computed(() => packages.value
  .filter(item => selected.value === 'all' || item.system === selected.value)
  .sort((a, b) => (order.indexOf(a.system) - order.indexOf(b.system)) || (a.rank - b.rank)));
</script>

<template>
  <div class="client-packages">
    <div class="package-platforms" role="group" aria-label="安装平台">
      <button v-for="(label, system) in platforms" :key="system" type="button" :aria-pressed="selected === system" @click="selected = system">
        {{ label }}
      </button>
    </div>
    <div class="package-results" aria-live="polite">
      <p v-if="selected === 'harmony'">鸿蒙客户端适配中。</p>
      <p v-else-if="!selected">请选择要安装客户端的电脑系统。</p>
      <p v-else-if="!packages.length">{{ emptyText }}</p>
      <p v-else-if="!visiblePackages.length">当前没有 {{ platforms[selected] }} 安装包。点击「其他」查看全部版本。</p>
      <template v-else>
        <p v-if="visiblePackages.some(item => item.system === 'macos')">Mac 可在苹果菜单的「关于本机」中查看芯片型号。</p>
        <a v-for="item in visiblePackages" :key="item.download_path" class="package-download" :href="backendUrl(item.download_path)" :title="item.file_name" target="_blank" rel="noopener noreferrer">
          <span>
            <strong>{{ item.title }} <small v-if="item.version">v{{ item.version }}</small></strong>
            <small>{{ item.detail }} · {{ (item.size / 1048576).toFixed(1) }} MB</small>
          </span>
          <Download :size="18" aria-hidden="true" />
        </a>
      </template>
    </div>
  </div>
</template>

<style scoped>
.client-packages { min-width: 0; color: var(--text, var(--app-text, #253246)); }
.package-platforms { display: flex; flex-wrap: wrap; gap: 6px; }
.client-packages .package-platforms button {
  min-height: 36px;
  padding: 6px 12px;
  border: 1px solid var(--line, var(--app-line, #d8e0eb));
  border-radius: 6px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.client-packages .package-platforms button:is(:hover, [aria-pressed="true"]) {
  background: var(--primary-soft, var(--app-primary-soft, #e8f0ff));
  color: var(--primary-ink, var(--app-primary, #2563a8));
  border-color: currentColor;
}
.package-results p { margin: 14px 0 4px; font-size: 13px; color: var(--muted, var(--app-text-secondary, #5f6e80)); }
.package-download {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 0;
  border-bottom: 1px solid var(--line, var(--app-line, #d8e0eb));
  color: var(--primary-ink, var(--app-primary, #2563a8));
  text-decoration: none;
}
.package-download:hover strong { text-decoration: underline; }
.package-download > span { display: grid; min-width: 0; gap: 4px; overflow-wrap: anywhere; }
.package-download svg { flex-shrink: 0; }
.package-download small { color: var(--muted, var(--app-text-secondary, #5f6e80)); font-size: 12px; font-weight: normal; }
</style>
