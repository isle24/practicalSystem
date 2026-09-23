<template>
  <Teleport to="body">
    <div v-if="show" class="wechat-binding-gate" role="dialog" aria-modal="true" aria-label="企业微信绑定">
      <section>
        <h2>绑定企业微信</h2>
        <p>当前账号：{{ accountName }}（{{ roleName }}）</p>
        <p>{{ state.message || guidance }}</p>
        <template v-if="!inWechat && !status?.bound">
          <label for="wechat-binding-url">学校网址</label>
          <input id="wechat-binding-url" :value="bindingUrl" readonly @focus="$event.target.select()">
          <button type="button" :disabled="state.loading" @click="$emit('copy')">复制学校网址</button>
        </template>
        <button v-if="inWechat && status?.identity_ready && !status?.bound && !status?.binding_outdated" type="button" :disabled="state.loading" @click="$emit('bind')">确认绑定当前账号</button>
        <button v-if="inWechat && (!status?.bound || !status?.identity_ready || !status?.identity_matches) && !status?.binding_outdated && status?.configured !== false" type="button" :disabled="state.loading" @click="$emit('start')">{{ state.loading ? '正在处理' : status?.bound || status?.identity_ready ? '重新获取企业微信身份' : '获取企业微信身份' }}</button>
        <button type="button" :disabled="state.loading" @click="$emit('recheck')">{{ state.loading ? '正在检查' : '重新检查绑定状态' }}</button>
        <button v-if="dismissible" type="button" :disabled="state.loading" @click="$emit('close')">返回系统</button>
        <button type="button" :disabled="state.loading" @click="$emit('logout')">退出当前账号</button>
      </section>
    </div>
  </Teleport>
</template>
<script setup>
import { computed } from 'vue';

const props = defineProps({ show: Boolean, state: Object, status: Object, accountName: String, roleName: String, inWechat: Boolean, bindingUrl: String, dismissible: Boolean });
defineEmits(['bind', 'start', 'logout', 'recheck', 'copy', 'close']);
const guidance = computed(() => {
  if (!props.inWechat) return '请在企业微信中打开学校网址，登录当前系统账号完成绑定，再返回此页面重新检查。';
  if (props.status?.identity_ready) return '已获取企业微信身份，请确认与当前系统账号绑定。';
  return '请先获取企业微信身份，再确认绑定当前系统账号。';
});
</script>
<style scoped>
.wechat-binding-gate { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; background: rgba(15,23,42,.65); padding: 20px; }
.wechat-binding-gate section { box-sizing: border-box; width: min(440px, 100%); padding: 24px; border-radius: 16px; background: white; color: #172033; }
h2 { margin: 0 0 16px; font-size: 21px; }
p { margin: 12px 0; line-height: 1.7; overflow-wrap: anywhere; }
label { display: block; margin-top: 12px; font-size: 14px; }
input { box-sizing: border-box; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; color: #172033; }
button { width: 100%; margin-top: 12px; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; background: #f8fafc; color: #172033; }
button:first-of-type { background: #2563eb; color: white; border-color: #2563eb; }
button:disabled { opacity: .6; cursor: wait; }
</style>
