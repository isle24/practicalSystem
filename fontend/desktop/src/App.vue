<template>
  <main class="connection-page">
    <header class="connection-heading">
      <span class="product-name">实践管理系统</span>
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
      <label for="school-username">账号</label>
      <input id="school-username" v-model="username" type="text" autocomplete="username" placeholder="请输入账号" :disabled="busy || loading">
      <label for="school-password">密码</label>
      <input id="school-password" v-model="password" type="password" autocomplete="current-password" placeholder="请输入密码" :disabled="busy || loading">
      <label for="school-remark">备注 <small>可选</small></label>
      <input id="school-remark" v-model="remark" maxlength="60" placeholder="例如：工作账号" :disabled="busy || loading">
      <div class="connection-options">
        <label class="check-option"><input v-model="rememberPassword" type="checkbox" :disabled="busy || loading"><span>记住密码</span></label>
        <label class="check-option"><input v-model="autoLogin" type="checkbox" :disabled="busy || loading || !rememberPassword"><span>自动登录</span></label>
      </div>
      <p v-if="error" id="connection-error" class="error" role="alert"><CircleAlert :size="17" />{{ error }}</p>
      <button class="connect-button" type="submit" :disabled="busy || loading || !domain.trim()">
        <LoaderCircle v-if="busy" :size="19" class="spin" />
        <span>{{ busy ? '正在连接' : username.trim() ? '登录' : '进入学校' }}</span>
        <ArrowRight v-if="!busy" :size="19" />
      </button>
    </form>

    <footer><LockKeyhole :size="13" /><span>学校独立登录</span><span class="version">v{{ version }}</span></footer>
  </main>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { invoke } from '@tauri-apps/api/core';
import { getVersion } from '@tauri-apps/api/app';
import { ArrowRight, ChevronRight, CircleAlert, Globe2, LoaderCircle, LockKeyhole, Plus, RefreshCw, X } from '@lucide/vue';

const domain = ref('');
const schoolName = ref('');
const remark = ref('');
const editingSchool = ref(true);
const switching = ref(false);
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
      editingSchool.value = false;
    }
    if (recent?.username) {
      username.value = recent.username;
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
  if (autoLogin.value && password.value) {
    await connect();
  }
});

// 使用所填域名建立独立学校会话。
async function connect() {
  if (busy.value || loading.value) return;
  if (Boolean(username.value.trim()) !== Boolean(password.value)) {
    error.value = '请完整填写账号和密码，或都留空后进入学校登录页';
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    const settings = await invoke('connect_school', {
      domain: domain.value,
      username: username.value,
      password: password.value,
      rememberPassword: rememberPassword.value,
      autoLogin: autoLogin.value,
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
  password.value = '';
  autoLogin.value = false;
  rememberPassword.value = false;
  try {
    if (school.username) {
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
  schoolName.value = ''; editingSchool.value = true; switching.value = false; error.value = '';
}
</script>
