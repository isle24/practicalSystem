<script setup>
import { computed } from 'vue';

const props = defineProps({
  required: { type: Boolean, default: undefined },
  submitRequired: { type: Boolean, default: false },
  help: { type: String, default: '' },
  error: { type: String, default: '' },
});

const requirementText = computed(() => {
  if (props.required) return '必填';
  if (props.submitRequired) return '提交时必填';
  return props.required === false ? '选填' : '';
});
</script>

<template>
  <span class="form-field-label">
    <span class="form-field-label-heading">
      <span><slot /></span>
      <small v-if="requirementText" class="form-field-requirement" :class="{ 'is-required': required || submitRequired }">{{ requirementText }}</small>
    </span>
    <span v-if="help" class="form-field-help">{{ help }}</span>
    <span v-if="error" class="form-field-error" role="alert">{{ error }}</span>
  </span>
</template>

<style scoped>
.form-field-label { display: grid; gap: 4px; min-width: 0; }
.form-field-label-heading { display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px; }
.form-field-requirement, .form-field-help, .form-field-error { font-size: 12px; line-height: 1.5; font-weight: 400; }
.form-field-requirement, .form-field-help { color: var(--muted, var(--app-text-secondary, #627083)); }
.form-field-requirement.is-required, .form-field-error { color: var(--danger, var(--app-danger, #c9364f)); }
.form-field-help, .form-field-error { overflow-wrap: anywhere; }
</style>
