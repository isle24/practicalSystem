<template>
  <img v-if="customUrl && failedUrl !== customUrl" class="app-icon" :src="customUrl" :width="size" :height="size" alt="" decoding="async" @error="failedUrl = customUrl">
  <svg v-else-if="styleKey === 'emoji'" class="app-icon menu-emoji" :width="size" :height="size" viewBox="0 0 48 48" aria-hidden="true">
    <text x="24" y="37" text-anchor="middle">{{ artwork.emoji }}</text>
  </svg>
  <svg v-else-if="styleKey === 'classic'" class="app-icon menu-classic" :data-color="color || artwork.color" :width="size" :height="size" viewBox="0 0 48 48" aria-hidden="true">
    <component :is="icon || artwork.glyph" x="11" y="11" :size="26" color="white" />
  </svg>
  <img v-else class="app-icon" :class="styleKey === 'glass' ? 'menu-artwork' : 'menu-illustration'" :src="artwork[styleKey]" :style="{ '--menu-object-tint': artwork.tint }" :width="size" :height="size" alt="" decoding="async">
</template>

<script setup>
import { computed, inject, ref } from 'vue';
import { menuArtwork } from '../menuArtwork.js';
import { DEFAULT_MENU_ICON_STYLE, MENU_ICON_STYLE } from '../uiStyles.js';

const props = defineProps({
  menuKey: { type: [String, Number], default: '' },
  icon: { type: [Object, Function], default: null },
  color: { type: String, default: '' },
  iconStyle: { type: String, default: '' },
  iconName: { type: String, default: '' },
  iconUrl: { type: String, default: '' },
  size: { type: Number, default: 24 },
  backendUrl: { type: Function, default: url => url },
});

const failedUrl = ref('');
const preference = inject(MENU_ICON_STYLE, null);
const styleKey = computed(() => props.iconStyle || preference?.value || DEFAULT_MENU_ICON_STYLE);
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

.app-icon.menu-emoji { border-radius: 0; }
.menu-emoji text { font: 38px 'Apple Color Emoji', 'Segoe UI Emoji', 'Noto Color Emoji', emoji, sans-serif; }
.menu-classic {
  background: linear-gradient(135deg, var(--classic-start, #1f73d2), var(--classic-end, #4b96e3));
  box-shadow: inset 0 1px 0 #ffffff3d, 0 2px 5px #18212b2e;
}
.menu-classic[data-color=teal] { --classic-start: #0f8f7e; --classic-end: #36aa92; }
.menu-classic[data-color=amber] { --classic-start: #b96d08; --classic-end: #d9992a; }
.menu-classic[data-color=red] { --classic-start: #c74350; --classic-end: #e05f6d; }
.menu-classic[data-color=gray] { --classic-start: #53606f; --classic-end: #7b8795; }
.menu-classic[data-color=green] { --classic-start: #2e7d4f; --classic-end: #57a46f; }
.menu-illustration { filter: var(--menu-object-finish, none); }

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
