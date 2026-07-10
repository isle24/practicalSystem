<template>
  <div v-if="visible" class="operation-mask" @click.self="emit('close')">
    <section class="operation-dialog" :class="dialogClass">
      <header>
        <strong>{{ title }}</strong>
        <button type="button" class="operation-dialog-close" title="关闭" aria-label="关闭" @click="emit('close')">
          <X :size="17" />
        </button>
      </header>
      <slot />
      <footer v-if="$slots.footer">
        <slot name="footer" />
      </footer>
    </section>
  </div>
</template>

<script setup>
import { X } from '@lucide/vue';

defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '' },
  dialogClass: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['close']);
</script>
