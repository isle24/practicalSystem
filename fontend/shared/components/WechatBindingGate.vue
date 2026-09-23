<template>
  <Teleport to="body">
    <div v-if="show" class="wechat-binding-gate" role="dialog" aria-modal="true" aria-label="企业微信绑定">
      <section>
        <h2>绑定企业微信</h2>
        <p>当前账号：{{ accountName }}（{{ roleName }}）</p>
        <p>{{ state.message || (status?.identity_ready ? '已获取企业微信身份，请确认与当前系统账号绑定。' : '需要先获取企业微信身份，完成绑定后继续使用。') }}</p>
        <button v-if="status?.identity_ready && !status?.bound" type="button" :disabled="state.loading" @click="$emit('bind')">确认绑定当前账号</button>
        <button type="button" :disabled="state.loading" @click="$emit('start')">{{ state.loading ? '正在处理' : '重新获取企业微信身份' }}</button>
        <button type="button" :disabled="state.loading" @click="$emit('logout')">退出当前账号</button>
      </section>
    </div>
  </Teleport>
</template>
<script setup>
defineProps({ show: Boolean, state: Object, status: Object, accountName: String, roleName: String });
defineEmits(['bind', 'start', 'logout']);
</script>
<style scoped>
.wechat-binding-gate { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; background: rgba(15,23,42,.65); padding: 20px; }
.wechat-binding-gate section { box-sizing: border-box; width: min(440px, 100%); padding: 24px; border-radius: 16px; background: white; color: #172033; }
h2 { margin: 0 0 16px; font-size: 21px; }
p { margin: 12px 0; line-height: 1.7; overflow-wrap: anywhere; }
button { width: 100%; margin-top: 12px; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; background: #f8fafc; color: #172033; }
button:first-of-type { background: #2563eb; color: white; border-color: #2563eb; }
button:disabled { opacity: .6; cursor: wait; }
</style>
