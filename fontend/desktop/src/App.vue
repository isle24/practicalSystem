<template>
  <main class="connection-page">
    <div class="app-mark" aria-hidden="true"><School :size="36" :stroke-width="1.6" /></div>
    <h1>实践管理系统</h1>
    <p class="subtitle">连接你的学校</p>

    <form class="connection-form" @submit.prevent="connect">
      <label for="school-domain">学校域名</label>
      <div class="domain-field" :class="{ invalid: error }">
        <Globe2 :size="19" aria-hidden="true" />
        <input id="school-domain" v-model="domain" type="text" autocomplete="url" autocapitalize="off"
          spellcheck="false" placeholder="sx.2iwm.com" :disabled="busy || loading" :aria-invalid="!!error"
          :aria-describedby="error ? 'connection-error' : undefined" autofocus>
      </div>
      <label for="school-username">账号</label>
      <input id="school-username" v-model="username" type="text" autocomplete="username" placeholder="请输入账号" :disabled="busy || loading">
      <label for="school-password">密码</label>
      <input id="school-password" v-model="password" type="password" autocomplete="current-password" placeholder="请输入密码" :disabled="busy || loading">
      <div class="connection-options">
        <label class="check-option"><input v-model="rememberPassword" type="checkbox" :disabled="busy || loading"><span>记住密码</span></label>
        <label class="check-option"><input v-model="autoLogin" type="checkbox" :disabled="busy || loading || !rememberPassword"><span>自动登录</span></label>
      </div>
      <p v-if="error" id="connection-error" class="error" role="alert"><CircleAlert :size="17" />{{ error }}</p>
      <button class="connect-button" type="submit" :disabled="busy || loading || !domain.trim()">
        <LoaderCircle v-if="busy" :size="19" class="spin" />
        <span>{{ busy ? '正在连接学校…' : '连接学校' }}</span>
        <ArrowRight v-if="!busy" :size="19" />
      </button>
    </form>

    <section v-if="profiles.length" class="recent-schools" aria-label="最近连接的学校">
      <h2>最近连接</h2>
      <button v-for="school in profiles" :key="`${school.origin}:${school.username || ''}`" class="school-row" :disabled="busy || loading"
        type="button" @click="selectSchool(school)">
        <span class="school-icon"><School :size="20" /></span>
        <span class="school-label"><strong>{{ school.name || '学校' }}</strong><small>{{ school.origin }}{{ school.username ? ` / ${school.username}` : '' }}</small></span>
        <ChevronRight :size="18" />
      </button>
    </section>
    <footer><LockKeyhole :size="13" /><span>学校独立登录</span><span class="version">v{{ version }}</span></footer>
  </main>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { invoke } from '@tauri-apps/api/core';
import { getVersion } from '@tauri-apps/api/app';
import { ArrowRight, ChevronRight, CircleAlert, Globe2, LoaderCircle, LockKeyhole, School } from '@lucide/vue';

const domain = ref('sx.2iwm.com');
const username = ref('');
const password = ref('');
const rememberPassword = ref(false);
const autoLogin = ref(false);
const profiles = ref([]);
const error = ref('');
const loading = ref(true);
const busy = ref(false);
const version = ref('0.3.1');

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
    });
    profiles.value = settings.schools;
    domain.value = settings.last_origin;
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
  if (autoLogin.value && password.value) await connect();
}
</script>
