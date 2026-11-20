<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import { request } from '../api/client';

const groups = [
  { name: '教务网关', fields: [
    { key: 'education_origins', label: '允许的教务网关来源', type: 'list', help: '每行一个 HTTPS 来源，可含端口。服务路径在本校教务数据同步中设置。留空会停止所有教务网关取数。' },
  ] },
  { name: '企业微信代理', fields: [
    { key: 'wechat_proxy_hosts', label: '允许的代理主机', type: 'list', help: '每行一个主机名。实际代理地址在本校企业微信设置中填写，留空会停用所有代理。' },
  ] },
  { name: '验证码短信', fields: [
    { key: 'sms_url', label: '验证码网关地址', type: 'url', help: '用于手机号验证和企业评价。填写 HTTPS 地址，可含服务路径，最多 500 字节。留空停用。' },
    { key: 'sms_token', label: '验证码网关凭据', type: 'password' },
  ] },
  { name: '客户端发布', fields: [
    { key: 'desktop_repository', label: 'GitHub 仓库', required: true, help: '填写所有者/仓库名，最多 160 字节。用于获取客户端版本清单和安装包。' },
    { key: 'desktop_token', label: 'GitHub 只读凭据', type: 'password' },
    { key: 'desktop_mirror_url', label: '公共仓库下载镜像', type: 'url', help: '填写公共 HTTPS 443 地址，可含路径，最多 500 字节。留空后各校均从 GitHub 下载。' },
  ] },
  { name: '单点登录', fields: [
    { key: 'sso_issuers', label: '启用签发的学校域名', type: 'domains', help: '从主库登记的学校域名中选择。移除域名将停止该域名的单点登录签发。' },
  ] },
];

const form = reactive({});
const issuers = reactive({});
const domains = ref({});
const selectedDomains = ref([]);
const ready = ref(false);
const busy = ref('');
const failure = ref('');
const saved = ref('');
const progress = ref('');
const domainOptions = computed(() => {
  const hosts = new Set([...Object.keys(domains.value), ...selectedDomains.value]);
  return Array.from(hosts, host => {
    const info = domains.value[host];
    const reason = info ? (info.available ? '' : '学校、数据库或域名授权已停用') : '该域名已取消登记';
    return { host, label: (info?.school ? info.school + ' · ' : '') + host + (reason ? '（' + reason + '）' : ''), reason };
  });
});

function fill(fields, data) {
  for (const field of fields) {
    const key = field.key;
    if (field.type === 'list') form[key] = data[key].join('\n');
    else if (field.type === 'domains') {
      domains.value = data.school_domains;
      selectedDomains.value = Object.keys(data[key]);
      Object.assign(issuers, data[key]);
    } else if (field.type === 'password') {
      form[key] = '';
      form[key + '_configured'] = data[key + '_configured'];
      form['clear_' + key] = false;
    } else form[key] = data[key];
  }
}

async function load() {
  busy.value = '读取';
  failure.value = '';
  try {
    const data = await request('/config/integrations');
    for (const group of groups) fill(group.fields, data);
    ready.value = true;
  } catch (error) { failure.value = error.message; }
  finally { busy.value = ''; }
}

function selectDomains(hosts) {
  selectedDomains.value = hosts;
  for (const host of hosts) issuers[host] ||= 'https://' + host;
}

async function save(group) {
  busy.value = group.name;
  progress.value = group.name;
  failure.value = '';
  saved.value = '';
  try {
    const payload = {};
    for (const { key, type } of group.fields) {
      if (type === 'list') payload[key] = form[key].split(/[\s,]+/).filter(Boolean);
      else if (type === 'domains') {
        payload[key] = selectedDomains.value.map(host => {
          const url = new URL(issuers[host]);
          if (url.protocol !== 'https:' || url.hostname.toLowerCase() !== host || url.pathname !== '/' || url.username || url.password || url.search || url.hash) {
            throw new Error('签发地址必须使用所选学校域名的 HTTPS 来源，可含端口。');
          }
          return issuers[host];
        });
      } else payload[key] = form[key];
      if (type === 'password') payload['clear_' + key] = form['clear_' + key];
    }
    const { csrf_token } = await request('/config/integrations');
    const data = await request('/config/integrations', {
      method: 'POST', headers: { 'X-CSRF-Token': csrf_token }, body: JSON.stringify(payload),
    });
    fill(group.fields, data);
    saved.value = group.name + '设置已保存';
  } catch (error) { failure.value = error.message; }
  finally { busy.value = ''; }
}

onMounted(load);
</script>

<template>
  <section class="integration-settings" aria-label="平台集成">
    <h3>平台集成</h3>
    <p>超级管理员维护，设置对共用此主库的所有学校生效。</p>
    <p v-if="!ready && busy" role="status">正在读取平台设置</p>
    <template v-if="ready">
      <details v-for="group in groups" :key="group.name">
        <summary>{{ group.name }}</summary>
        <form @submit.prevent="save(group)">
          <fieldset :disabled="Boolean(busy)">
            <template v-for="field in group.fields" :key="field.key">
              <label>
                <FormFieldLabel :required="field.required === true" :help="field.help || '最多 2048 字节，不能含空格或控制字符。留空保留当前凭据。'">{{ field.label }}</FormFieldLabel>
                <textarea v-if="field.type === 'list'" v-model="form[field.key]" rows="3" autocapitalize="off" spellcheck="false" />
                <el-select v-else-if="field.type === 'domains'" :model-value="selectedDomains" multiple filterable clearable
                  placeholder="选择学校域名" :disabled="Boolean(busy)" @change="selectDomains">
                  <el-option v-for="domain in domainOptions" :key="domain.host" :label="domain.label" :value="domain.host" :disabled="Boolean(domain.reason)" />
                </el-select>
                <input v-else v-model="form[field.key]" :type="field.type || 'text'" :required="field.required"
                  :disabled="field.type === 'password' && form['clear_' + field.key]" :autocomplete="field.type === 'password' ? 'new-password' : 'off'"
                  :maxlength="field.type === 'password' ? 2048 : field.key === 'desktop_repository' ? 160 : 500"
                  :placeholder="field.type === 'password' ? (form[field.key + '_configured'] ? '已配置' : '尚未配置') : ''">
              </label>
              <label v-if="field.type === 'password' && form[field.key + '_configured']" class="clear-credential">
                <input v-model="form['clear_' + field.key]" type="checkbox">
                <FormFieldLabel :required="false">清除现有凭据</FormFieldLabel>
              </label>
              <template v-if="field.type === 'domains'">
                <label v-for="host in selectedDomains" :key="host">
                  <FormFieldLabel required help="可填写自定义 HTTPS 端口，签发地址须与所选学校域名一致。">{{ host }} 签发地址</FormFieldLabel>
                  <input v-model.trim="issuers[host]" type="url" required :readonly="!domains[host]?.available" autocomplete="off">
                  <button v-if="!domains[host]?.available" class="secondary-button" type="button" @click="selectDomains(selectedDomains.filter(value => value !== host))">移除失效域名</button>
                </label>
              </template>
            </template>
            <button class="primary-button" type="submit">{{ busy === group.name ? '正在保存' : '保存' + group.name + '设置' }}</button>
          </fieldset>
          <p v-if="failure && progress === group.name" role="alert">{{ failure }}</p>
          <p v-if="saved && progress === group.name" role="status">{{ saved }}</p>
        </form>
      </details>
    </template>
    <div v-else-if="failure">
      <p role="alert">{{ failure }}</p>
      <button type="button" class="secondary-button" @click="load">重新读取</button>
    </div>
  </section>
</template>

<style scoped>
.integration-settings { padding: 20px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); }
h3 { margin: 0; font-size: 15px; }
p { color: var(--muted); font-size: 13px; line-height: 1.6; }
details { border-top: 1px solid var(--line); }
summary { padding: 12px 0; cursor: pointer; font-weight: 600; }
fieldset { min-width: 0; max-width: 620px; border: 0; padding: 2px 0 16px; margin: 0; display: grid; gap: 16px; }
label { display: grid; gap: 6px; min-width: 0; }
.clear-credential { display: flex; align-items: center; gap: 8px; }
input:not([type=checkbox]), textarea { width: 100%; box-sizing: border-box; padding: 9px 12px; color: inherit; font: inherit; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
textarea { resize: vertical; }
button { justify-self: start; }
[role=alert] { color: var(--danger); }
[role=status] { color: var(--primary-ink); }
</style>
