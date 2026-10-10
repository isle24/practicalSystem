<template>
  <div ref="root" class="profile-menu">
    <button ref="trigger" type="button" class="profile-menu-trigger" :class="{ 'profile-menu-avatar-only': avatarOnly }" :aria-label="`${name || '用户'}的个人菜单`" :title="name" :aria-expanded="open" aria-haspopup="dialog" @click="toggle" @keydown.down.prevent="show">
      <span class="top-avatar" :style="avatarStyle"><UserRound v-if="!avatar" :size="16" /></span><template v-if="!avatarOnly"><span class="profile-menu-name">{{ name }}</span><ChevronDown :size="14" /></template>
    </button>
    <Teleport to="body">
      <section v-if="open" ref="panel" class="profile-menu-panel" :style="position" role="dialog" aria-label="个人菜单" @keydown.esc.stop.prevent="close(true)">
        <header><strong>{{ name }}</strong><small>{{ role }}</small></header>
        <button type="button" @click="act('settings')"><Settings :size="16" />个人设置</button>
        <template v-if="accounts.length">
          <button type="button" :disabled="switching" :aria-expanded="identities" @click="identities = !identities"><UsersRound :size="16" />切换身份<ChevronDown :size="14" /></button>
          <div v-if="identities" class="profile-menu-identities"><button v-for="account in accounts" :key="account.id" type="button" :disabled="switching" @click="act('switch', account.id)"><strong>{{ accountName(account) }}</strong><small>{{ accountMeta(account) }}</small></button></div>
        </template>
        <div class="profile-menu-status"><span><i />在线</span><span>客户端 v{{ version }}</span></div>
        <small v-if="error" class="profile-menu-error" role="alert">{{ error }}</small>
        <button type="button" class="profile-menu-logout" :disabled="busy" @click="act('logout')"><LogOut :size="16" />退出登录</button>
      </section>
    </Teleport>
  </div>
</template>
<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronDown, LogOut, Settings, UserRound, UsersRound } from '@lucide/vue';
import { desktopTopInset } from '../composables/viewportArea';
const props = defineProps({ name: String, role: String, avatar: String, avatarStyle: Object, version: String, avatarOnly: Boolean, accounts: { type: Array, default: () => [] }, accountName: Function, accountMeta: Function, placement: { type: String, default: 'top' }, switching: Boolean, busy: Boolean, error: String, contextKey: [String, Number] });
const emit = defineEmits(['settings', 'switch', 'logout']);
const root = ref(null), trigger = ref(null), panel = ref(null), open = ref(false), identities = ref(false), position = ref({});
let observer;
function place() {
  const rect = trigger.value?.getBoundingClientRect();
  if (!rect || !panel.value) return;
  const topInset = desktopTopInset();
  const available = Math.max(0, window.innerHeight - topInset - 16);
  const height = Math.min(panel.value.scrollHeight, available);
  const desired = props.placement === 'bottom' ? rect.bottom + 6 : rect.top - height - 6;
  position.value = { top: `${Math.max(topInset + 8, Math.min(desired, window.innerHeight - height - 8))}px`, left: `${Math.max(8, Math.min(rect.right - Math.min(290, window.innerWidth - 16), window.innerWidth - 298))}px`, maxHeight: `${available}px` };
}
async function show() { open.value = true; await nextTick(); place(); panel.value?.querySelector('button')?.focus(); }
function toggle() { if (open.value) close(true); else show(); }
function close(restore = false) { open.value = false; identities.value = false; if (restore) trigger.value?.focus(); }
function act(action, value) { close(true); emit(action, value); }
function outside(event) { if (open.value && !root.value?.contains(event.target) && !panel.value?.contains(event.target)) close(); }
function escape(event) { if (event.key === 'Escape' && open.value) close(true); }
watch(() => props.contextKey, () => close());
watch(identities, async () => { await nextTick(); place(); });
onMounted(() => { document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape); window.addEventListener('resize', place); window.addEventListener('scroll', place, true); if (typeof ResizeObserver !== 'undefined') { observer = new ResizeObserver(place); observer.observe(document.documentElement); } });
onBeforeUnmount(() => { observer?.disconnect(); document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true); });
defineExpose({ close });
</script>
