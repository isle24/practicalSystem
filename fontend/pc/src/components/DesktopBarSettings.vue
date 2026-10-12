<template>
  <section class="profile-panel-card desktop-bar-settings">
    <header><strong>本机栏位</strong><small>选择后立即生效，只保存在当前设备，不上传服务器。</small></header>
    <div v-if="native" class="desktop-bar-setting">
      <div><strong id="desktop-status-bar-label">顶部状态栏自动隐藏</strong><small id="desktop-status-bar-description">开启后，悬停顶部或键盘聚焦时显示；关闭后始终显示。</small></div>
      <el-switch :model-value="preferences.autoHideStatusBar" aria-labelledby="desktop-status-bar-label" aria-describedby="desktop-status-bar-description" @change="emit('change', 'autoHideStatusBar', $event)" />
    </div>
    <div class="desktop-bar-setting">
      <div><strong id="desktop-dock-label">底部 Dock 自动隐藏</strong><small id="desktop-dock-description">开启后，模块最大化时自动隐藏，悬停底部或键盘聚焦时显示；关闭后始终显示。</small></div>
      <el-switch :model-value="preferences.autoHideDock" aria-labelledby="desktop-dock-label" aria-describedby="desktop-dock-description" @change="emit('change', 'autoHideDock', $event)" />
    </div>
    <p v-if="preferences.error" class="desktop-bar-error" role="alert">{{ preferences.error }}</p>
  </section>
</template>
<script setup>
defineProps({ preferences: { type: Object, required: true }, native: { type: Boolean, default: false } });
const emit = defineEmits(['change']);
</script>
<style scoped>
.desktop-bar-setting{display:flex;align-items:center;justify-content:space-between;gap:20px}.desktop-bar-setting>div{display:flex;flex-direction:column;gap:6px}.desktop-bar-setting strong{font-size:14px;font-weight:500;color:var(--text)}.desktop-bar-setting small{font-size:12px;line-height:1.6;color:var(--muted)}.desktop-bar-setting :deep(.el-switch){flex-shrink:0}.desktop-bar-error{margin:0;color:var(--el-color-danger,#dc2626);font-size:13px;line-height:1.6}
</style>
