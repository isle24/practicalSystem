<template>
  <main class="connection-page">
    <header class="connection-heading">
      <span class="product-name">实践系统</span>
      <h1>{{ editingSchool ? '连接学校' : schoolName }}</h1>
      <div v-if="!editingSchool" class="school-identity">
        <span>{{ remark || '学校账号登录' }}</span>
        <button type="button" class="switch-school" :disabled="busy || loading" @click="switching = !switching"><RefreshCw :size="14" />切换</button>
      </div>
    </header>

    <section v-if="switching" class="recent-schools" aria-label="切换学校">
      <div class="history-heading"><h2>学校与账号</h2><button type="button" class="icon-button" aria-label="关闭切换" @click="switching = false"><X :size="16" /></button></div>
      <button v-for="school in profiles" :key="`${school.origin}:${school.username || ''}`" class="school-row" :disabled="busy || loading" type="button" @click="selectSchool(school)">
        <span class="school-label"><strong>{{ school.name || '学校' }}</strong><small>{{ [school.remark, school.username].filter(Boolean).join(' · ') }}</small></span>
        <ChevronRight :size="18" />
      </button>
      <button class="add-school" type="button" @click="newSchool"><Plus :size="16" />连接其他学校</button>
    </section>

    <form v-else class="connection-form" @submit.prevent="connect">
      <template v-if="editingSchool">
      <label for="school-domain">学校域名</label>
      <div class="domain-field" :class="{ invalid: error }">
        <Globe2 :size="19" aria-hidden="true" />
        <input id="school-domain" v-model="domain" type="text" autocomplete="url" autocapitalize="off"
          spellcheck="false" placeholder="请输入学校服务器域名" :disabled="busy || loading" :aria-invalid="!!error"
          :aria-describedby="error ? 'connection-error' : undefined" autofocus>
      </div>
      <button v-if="profiles.length" class="switch-school history-entry" type="button" @click="switching = true">选择已连接的学校<ChevronRight :size="14" /></button>
      </template>
      <div class="login-methods" aria-label="登录方式">
        <button type="button" :aria-pressed="loginMethod === 'password'" :disabled="busy || loading" @click="selectLoginMethod('password')"><LockKeyhole :size="16" />密码登录</button>
        <button type="button" :aria-pressed="loginMethod === 'wechat'" :disabled="busy || loading" @click="selectLoginMethod('wechat')"><QrCode :size="16" />企业微信扫码登录</button>
      </div>
      <template v-if="loginMethod === 'password'">
      <label for="school-username">账号</label>
      <input id="school-username" v-model="username" type="text" autocomplete="username" placeholder="请输入账号" :disabled="busy || loading">
      <label for="school-password">密码</label>
      <input id="school-password" v-model="password" type="password" autocomplete="current-password" placeholder="请输入密码" :disabled="busy || loading">
      </template>
      <p v-else class="connection-guidance">连接学校后将显示登录二维码，请使用已绑定账号的企业微信扫码。</p>
      <label for="school-remark">备注 <small>可选</small></label>
      <input id="school-remark" v-model="remark" maxlength="60" placeholder="例如：工作账号" :disabled="busy || loading">
      <div v-if="loginMethod === 'password'" class="connection-options">
        <label class="check-option"><input v-model="rememberPassword" type="checkbox" :disabled="busy || loading || !username.trim() || !password"><span>记住密码</span></label>
        <label class="check-option"><input v-model="autoLogin" type="checkbox" :disabled="busy || loading || !rememberPassword"><span>自动登录</span></label>
      </div>
      <p v-if="error" id="connection-error" class="error" role="alert"><CircleAlert :size="17" />{{ error }}</p>
      <button class="connect-button" type="submit" :disabled="busy || loading || !domain.trim()">
        <LoaderCircle v-if="busy" :size="19" class="spin" />
        <span>{{ busy ? '正在连接' : loginMethod === 'wechat' ? '连接学校并扫码' : '登录' }}</span>
        <ArrowRight v-if="!busy" :size="19" />
      </button>
    </form>

    <footer><LockKeyhole :size="13" /><span>学校独立登录</span><span class="version">v{{ version }}</span></footer>
  </main>
</template>

<script setup>
import { ref, onBeforeUnmount, onMounted, watch } from 'vue';
import { invoke } from '@tauri-apps/api/core';
import { getVersion } from '@tauri-apps/api/app';
import { ArrowRight, ChevronRight, CircleAlert, Globe2, LoaderCircle, LockKeyhole, Plus, QrCode, RefreshCw, X } from '@lucide/vue';

const domain = ref('');
const schoolName = ref('');
const remark = ref('');
const editingSchool = ref(true);
const switching = ref(false);
const loginMethod = ref('password');
const username = ref('');
const password = ref('');
const rememberPassword = ref(false);
const autoLogin = ref(false);
const profiles = ref([]);
const error = ref('');
const loading = ref(true);
const busy = ref(false);
const version = ref('');

watch([domain, username], () => {
  if (loading.value || busy.value) return;
  password.value = '';
  rememberPassword.value = false;
  autoLogin.value = false;
}, { flush: 'sync' });
watch(rememberPassword, value => { if (!value) autoLogin.value = false; }, { flush: 'sync' });

// 加载本机保存的学校连接。
onMounted(async () => {
  try {
    const settings = await invoke('read_connection_settings');
    profiles.value = settings.schools;
    domain.value = settings.last_origin || domain.value;
    const recent = settings.schools.find(item => item.origin === domain.value && item.username === settings.last_username)
      || settings.schools.find(item => item.origin === domain.value);
    if (recent) {
      schoolName.value = recent.name;
      remark.value = recent.remark || '';
      loginMethod.value = profileLoginMethod(recent);
      editingSchool.value = false;
    }
    if (recent?.username) username.value = recent.username;
    if (recent?.username && loginMethod.value === 'password') {
      const saved = await invoke('read_saved_connection_password', { origin: recent.origin, username: recent.username });
      password.value = saved || '';
      rememberPassword.value = Boolean(saved);
      autoLogin.value = Boolean(saved && recent.auto_login);
    }
    version.value = await getVersion();
  } catch (reason) {
    error.value = String(reason);
  } finally {
    loading.value = false;
  }
  window.addEventListener('focus', refreshProfiles);
  if (autoLogin.value && password.value) {
    await connect();
  }
});

onBeforeUnmount(() => window.removeEventListener('focus', refreshProfiles));

function profileLoginMethod(profile) {
  return profile.login_method === 'wechat' || (!profile.login_method && !profile.username) ? 'wechat' : 'password';
}

async function refreshProfiles() {
  if (busy.value || loading.value) return;
  try {
    const settings = await invoke('read_connection_settings');
    profiles.value = settings.schools;
  } catch (reason) {
    error.value = String(reason);
  }
}

async function selectLoginMethod(method) {
  if (busy.value || loading.value || loginMethod.value === method) return;
  loginMethod.value = method;
  password.value = '';
  rememberPassword.value = false;
  autoLogin.value = false;
  error.value = '';
  if (method !== 'password' || !username.value.trim() || editingSchool.value) return;
  loading.value = true;
  try {
    const saved = await invoke('read_saved_connection_password', { origin: domain.value, username: username.value });
    password.value = saved || '';
    rememberPassword.value = Boolean(saved);
  } catch (reason) {
    error.value = String(reason);
  } finally {
    loading.value = false;
  }
}

// 使用所填域名建立独立学校会话。
async function connect() {
  if (busy.value || loading.value) return;
  const passwordLogin = loginMethod.value === 'password';
  if (passwordLogin && (!username.value.trim() || !password.value)) {
    error.value = '请输入账号和密码，或选择企业微信扫码登录';
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    const settings = await invoke('connect_school', {
      domain: domain.value,
      loginMethod: loginMethod.value,
      username: passwordLogin ? username.value : '',
      password: passwordLogin ? password.value : '',
      rememberPassword: Boolean(passwordLogin && rememberPassword.value),
      autoLogin: Boolean(passwordLogin && autoLogin.value),
      remark: remark.value,
    });
    profiles.value = settings.schools;
    domain.value = settings.last_origin;
    const connected = settings.schools.find(item => item.origin === settings.last_origin && item.username === settings.last_username);
    if (connected) { schoolName.value = connected.name; editingSchool.value = false; }
  } catch (reason) {
    error.value = String(reason);
  } finally {
    busy.value = false;
  }
}

// 选择最近使用的学校。
async function selectSchool(school) {
  if (busy.value || loading.value) return;
  loading.value = true;
  error.value = '';
  domain.value = school.origin;
  schoolName.value = school.name;
  remark.value = school.remark || '';
  editingSchool.value = false;
  switching.value = false;
  username.value = school.username || '';
  loginMethod.value = profileLoginMethod(school);
  password.value = '';
  autoLogin.value = false;
  rememberPassword.value = false;
  try {
    if (school.username && loginMethod.value === 'password') {
      const saved = await invoke('read_saved_connection_password', { origin: school.origin, username: school.username });
      password.value = saved || '';
      rememberPassword.value = Boolean(saved);
      autoLogin.value = Boolean(saved && school.auto_login);
    }
  } catch (reason) {
    error.value = String(reason);
  } finally {
    loading.value = false;
  }
}

// 切换到域名输入时不携带原学校的凭据。
function newSchool() {
  if (busy.value || loading.value) return;
  domain.value = ''; username.value = ''; password.value = ''; remark.value = '';
  rememberPassword.value = false; autoLogin.value = false;
  schoolName.value = ''; editingSchool.value = true; switching.value = false; error.value = '';
}
</script>

<style scoped>
.connection-guidance { margin: 0 0 14px; color: var(--connection-muted, #5f6b78); font-size: 13px; line-height: 1.6; }
</style>
