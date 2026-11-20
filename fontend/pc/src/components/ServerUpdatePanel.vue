<template>
  <el-button v-if="allowed" :icon="ServerCog" :loading="busy" @click="pull()">拉取更新</el-button>
  <OperationDialog :visible="visible" title="服务器更新" :busy="busy" @close="close">
    <div class="pull-result" aria-live="polite">
      <p v-if="busy">正在执行 git pull --ff-only…</p>
      <template v-else>
        <el-alert :type="error ? 'error' : 'success'" :title="error || 'Git 拉取成功'" :closable="false" />
        <p v-if="result">Git 退出码 {{ result.exit_code }}</p>
        <pre v-if="result?.output" aria-label="Git 输出">{{ result.output }}</pre>
        <form v-if="result?.credentials_required" class="git-credentials" autocomplete="off" @submit.prevent="retry">
          <label>
            <span>Git 用户名（必填）</span>
            <el-input v-model="username" maxlength="255" required />
          </label>
          <label>
            <span>Git 密码或访问令牌（必填）</span>
            <el-input v-model="password" type="password" maxlength="4096" required />
          </label>
          <el-button native-type="submit" type="primary" :disabled="!username.trim() || !password">
            登录并重试拉取
          </el-button>
        </form>
      </template>
    </div>
  </OperationDialog>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { ServerCog } from '@lucide/vue';
import { request } from '../api/client';
import OperationDialog from './OperationDialog.vue';

const allowed = ref(false);
const busy = ref(false);
const visible = ref(false);
const result = ref(null);
const error = ref('');
const username = ref('');
const password = ref('');

function status() {
  return request('/server-update/status', { refreshOnUnauthorized: false });
}

onMounted(async () => {
  try {
    allowed.value = (await status()).allowed;
  } catch (e) {
    allowed.value = ![401, 403].includes(e.status);
  }
});

function close() {
  visible.value = false;
  username.value = '';
  password.value = '';
}

function retry() {
  const credentials = { username: username.value.trim(), password: password.value };
  password.value = '';
  return pull(credentials);
}

async function pull(credentials = null) {
  if (busy.value) return;
  visible.value = busy.value = true;
  error.value = '';
  result.value = null;
  try {
    const access = await status();
    if (!access.allowed) throw new Error('无服务器更新权限');
    result.value = await request('/server-update/pull', {
      method: 'POST', refreshOnUnauthorized: false, timeoutMs: 300000,
      headers: { 'X-CSRF-Token': access.csrf_token },
      body: credentials ? JSON.stringify(credentials) : undefined,
    });
    username.value = '';
  } catch (e) {
    error.value = e.message;
    result.value = e.payload?.data;
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.pull-result { padding: 20px; overflow: auto; color: var(--text); }
.pull-result p { margin: 0 0 12px; }
.pull-result .el-alert { margin-bottom: 14px; }
.pull-result pre { margin: 0; padding: 12px; border-radius: 8px; background: var(--surface-2); white-space: pre-wrap; overflow-wrap: anywhere; font: 13px/1.6 monospace; }
.git-credentials { display: grid; gap: 12px; margin-top: 16px; }
.git-credentials label { display: grid; gap: 6px; }
.git-credentials .el-button { justify-self: start; }
</style>
