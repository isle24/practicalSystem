<script setup>
import { computed } from 'vue';
import { UserRound, Palette, ShieldCheck, Bell, PenLine, HardDrive } from '@lucide/vue';
const props = defineProps({ modelValue: { type: String, default: 'general' }, desktop: Boolean });
const emit = defineEmits(['update:modelValue']);
const tabs = computed(() => [
  { id: 'general', label: '基本资料', icon: UserRound, hint: '管理头像、姓名和联系信息。' },
  { id: 'appearance', label: '主题与壁纸', icon: Palette, hint: '选择桌面主题，调整当前设备的外观。' },
  { id: 'accounts', label: '账号与安全', icon: ShieldCheck, hint: '管理账号关联和登录身份。' },
  { id: 'notifications', label: '消息通知', icon: Bell, hint: '选择接收渠道与企业微信通知时间。' },
  { id: 'signature', label: '电子签名', icon: PenLine, hint: '管理用于审批的个人电子签名。' },
  ...(props.desktop ? [{ id: 'storage', label: '存储与缓存', icon: HardDrive, hint: '管理本机文件预览缓存。' }] : []),
]);
const current = computed(() => tabs.value.find(tab => tab.id === props.modelValue) || tabs.value[0]);
</script>

<template>
  <div class="profile-layout">
    <aside class="profile-navigation">
      <div class="profile-navigation-title">个人设置</div>
      <nav aria-label="个人设置分类">
        <button v-for="tab in tabs" :key="tab.id" type="button" :class="{ active: current.id === tab.id }" :aria-current="current.id === tab.id ? 'page' : undefined" @click="emit('update:modelValue', tab.id)"><component :is="tab.icon" :size="18" /><span>{{ tab.label }}</span></button>
      </nav>
      <p>学校标识与默认壁纸<br>请前往学校设置</p>
    </aside>
    <section class="profile-detail">
      <header class="profile-detail-header">
        <div><h1>{{ current.label }}</h1><p>{{ current.hint }}</p></div>
        <div v-if="['general', 'notifications'].includes(current.id)" class="profile-detail-actions"><slot name="actions" /></div>
      </header>
      <div :key="current.id" class="profile-detail-body">
        <slot name="message" />
        <slot :name="current.id" />
      </div>
    </section>
  </div>
</template>

<style scoped>
.profile-layout { height: 100%; min-height: 0; display: grid; grid-template-columns: 188px minmax(0, 1fr); background: var(--surface-2); container-type: inline-size; }
.profile-navigation { display: flex; flex-direction: column; gap: 20px; padding: 24px 12px; border-right: 1px solid var(--line); background: var(--surface); }
.profile-navigation-title { padding: 0 14px; font-size: 17px; font-weight: 650; }
nav { display: grid; gap: 6px; }
nav button { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 8px; color: var(--muted); background: transparent; text-align: left; font-size: 13px; white-space: nowrap; }
nav button:hover { background: var(--surface-2); color: var(--text); }
nav button.active { color: var(--primary); background: var(--primary-soft); font-weight: 600; }
.profile-navigation p { padding: 0 14px; margin: auto 0 0; color: var(--muted); font-size: 11px; line-height: 1.8; }
.profile-detail { min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.profile-detail-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 24px 28px 20px; border-bottom: 1px solid var(--line); background: var(--surface); }
h1 { font-size: 20px; margin: 0; letter-spacing: .02em; }
.profile-detail-header p { margin: 8px 0 0; font-size: 12px; line-height: 1.6; color: var(--muted); }
.profile-detail-actions { display: flex; gap: 8px; flex-shrink: 0; }
.profile-detail-actions :deep(.el-button + .el-button) { margin-left: 0; }
.profile-detail-body { flex: 1; min-height: 0; overflow: auto; padding: 24px 28px; display: flex; flex-direction: column; gap: 20px; }
.profile-detail-body > :deep(*) { flex-shrink: 0; }
.profile-detail-body :deep(.profile-panel-card), .profile-detail-body :deep(.account-links), .profile-detail-body :deep(.signature-settings), .profile-detail-body :deep(.credential-vault), .profile-detail-body :deep(.preview-cache-settings) { width: 100%; padding: 22px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); color: var(--text); }
.profile-detail-body :deep(.profile-card-main) { gap: 26px; }
.profile-detail-body :deep(.profile-form-grid) { gap: 20px; }
.profile-detail-body :deep(.profile-form-grid input) { height: 40px; }
.profile-detail-body :deep(h3) { font-size: 15px; }
.profile-detail-body :deep(.client-version) { color: var(--muted); margin-top: 12px; }
@container (max-width: 740px) {
  .profile-layout { grid-template-columns: 1fr; grid-template-rows: auto minmax(0,1fr); }
  .profile-navigation { padding: 12px; gap: 0; border-right: 0; border-bottom: 1px solid var(--line); }
  .profile-navigation-title, .profile-navigation p { display: none; }
  nav { display: flex; overflow-x: auto; gap: 4px; }
  nav button { padding: 10px 12px; }
  .profile-detail-header { padding: 18px; flex-wrap: wrap; }
  .profile-detail-body { padding: 18px; }
}
@media (max-width: 740px) { .profile-layout { grid-template-columns: 1fr; grid-template-rows: auto minmax(0,1fr); } }
</style>
