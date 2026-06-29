<template>
  <div v-if="visible" class="operation-mask" @click.self="emit('close')">
    <section class="operation-dialog" :class="dialogClass">
      <header>
        <strong>{{ title }}</strong>
        <button type="button" @click="emit('close')">关闭</button>
      </header>
      <slot />
      <footer v-if="$slots.footer">
        <slot name="footer" />
      </footer>
    </section>
  </div>
</template>

<script setup>
defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '' },
  dialogClass: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['close']);
</script>
