<template>
  <img v-if="customUrl && failedUrl !== customUrl" class="app-icon" :src="customUrl" :width="size" :height="size" alt="" decoding="async" @error="failedUrl = customUrl">
  <img v-else class="app-icon menu-artwork" :src="artwork.url" :style="{ '--menu-object-tint': artwork.tint }" :width="size" :height="size" alt="" decoding="async">
</template>

<script setup>
import { computed, ref } from 'vue';
import { menuArtwork } from '../menuArtwork.js';

const props = defineProps({
  menuKey: { type: [String, Number], default: '' },
  iconName: { type: String, default: '' },
  iconUrl: { type: String, default: '' },
  size: { type: Number, default: 24 },
  backendUrl: { type: Function, default: url => url },
});

const failedUrl = ref('');
const customUrl = computed(() => props.iconUrl ? props.backendUrl(props.iconUrl) : '');
const artwork = computed(() => menuArtwork(props.menuKey, props.iconName));
</script>

<style scoped>
.app-icon {
  display: block;
  flex-shrink: 0;
  object-fit: cover;
  border-radius: 25%;
}

.app-icon.menu-artwork {
  box-sizing: border-box;
  border-radius: var(--menu-glass-corner, 25%);
  border: 1px solid var(--menu-glass-edge, #e3ecf4bd);
  border-bottom-color: var(--menu-glass-foot, var(--menu-glass-edge, #d1dce5ac));
  background:
    linear-gradient(150deg, var(--menu-glass-glint, #ffffffa6), transparent 53%, #17304c1f),
    radial-gradient(ellipse at 58% 100%, rgb(var(--menu-object-tint) / .34), transparent 78%),
    var(--menu-glass-wash, #dce8f35c);
  box-shadow: inset 0 1px 1px var(--menu-glass-glint, #ffffffa6), inset 0 -1px 1px #26394f2b,
    0 2px 4px #10243a29;
  -webkit-backdrop-filter: var(--menu-glass-blur, blur(4px));
  backdrop-filter: var(--menu-glass-blur, blur(4px));
  filter: var(--menu-object-finish, none);
}
@supports (corner-shape: squircle) {
  .app-icon { border-radius: 50%; corner-shape: squircle; }
  .app-icon.menu-artwork { border-radius: var(--menu-glass-corner, 50%); }
}
@media (prefers-reduced-transparency: reduce) {
  .app-icon.menu-artwork {
    background-color: var(--skin-solid, var(--surface, #f4f6f8));
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
  }
}
</style>
