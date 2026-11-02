<script setup>
import { computed, ref, watch } from 'vue';
import { findOperationGuide } from '../operationGuideData';

const props = defineProps({
  module: { type: String, default: '' },
  panel: { type: String, default: '' },
  parentPanel: { type: String, default: '' },
  pageTitle: { type: String, default: '' },
  nextAction: { type: Object, default: null },
  navigate: { type: Function, default: null },
});
const guide = computed(() => findOperationGuide(props.module, props.panel, props.parentPanel));
const pageTitle = computed(() => props.pageTitle || guide.value?.title || '');
const canNavigate = computed(() => typeof props.navigate === 'function'
  && typeof props.nextAction?.module === 'string' && Boolean(props.nextAction.module.trim())
  && typeof props.nextAction?.label === 'string' && Boolean(props.nextAction.label.trim()));
const navigating = ref(false);
const navigationError = ref('');
watch(() => [props.module, props.panel, props.parentPanel], () => { navigationError.value = ''; });

async function openNext() {
  if (!canNavigate.value || navigating.value) return;
  navigating.value = true;
  navigationError.value = '';
  try {
    await props.navigate({ module: props.nextAction.module, panel: props.nextAction.panel || '' });
  } catch (error) {
    navigationError.value = error?.message || '暂时无法打开目标页面';
  } finally {
    navigating.value = false;
  }
}
</script>

<template>
  <section v-if="guide" class="operation-route" aria-label="页面操作参考">
    <div class="operation-route-heading">
      <span>当前页面</span><strong>{{ pageTitle }}</strong><small>操作参考</small>
    </div>
    <p v-if="guide.next?.length" class="operation-route-next"><span>下一步</span>{{ guide.next[0] }}</p>
    <button v-if="canNavigate" class="operation-route-action" type="button" :disabled="navigating" @click="openNext">{{ nextAction.label }}</button>
    <p v-if="navigationError" class="operation-route-error" role="alert">{{ navigationError }}</p>
    <details :key="guide.key" class="operation-route-details">
      <summary>操作步骤</summary>
      <p v-if="guide.purpose">{{ guide.purpose }}</p>
      <ul v-if="guide.prerequisites?.length" class="operation-route-prerequisites">
        <li v-for="(item, index) in guide.prerequisites" :key="index">{{ item }}</li>
      </ul>
      <ol>
        <li v-for="(step, index) in guide.steps" :key="index">
          <strong>{{ step.action }}</strong>
          <p v-if="step.expected">完成标志：{{ step.expected }}</p>
          <p v-if="step.next">下一步：{{ step.next }}</p>
        </li>
      </ol>
      <div v-if="guide.issues?.length" class="operation-route-issues">
        <strong>无法继续时</strong>
        <ul><li v-for="(item, index) in guide.issues" :key="index">{{ item }}</li></ul>
      </div>
      <div v-if="guide.next?.length" class="operation-route-follow-up">
        <strong>完成后</strong>
        <ul><li v-for="(item, index) in guide.next" :key="index">{{ item }}</li></ul>
      </div>
    </details>
  </section>
</template>

<style scoped>
.operation-route { flex: 0 0 auto; min-width: 0; margin-bottom: 12px; padding: 10px 12px; color: var(--text, var(--app-text, #202b3a)); background: var(--surface-2, var(--app-surface-muted, #f5f7fa)); border-radius: var(--control-radius, 6px); font-size: 12px; line-height: 1.6; overflow-wrap: anywhere; }
.operation-route-heading { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.operation-route-heading > span, .operation-route-heading > small, .operation-route-next > span { color: var(--muted, var(--app-text-secondary, #627083)); font-size: inherit; }
.operation-route-heading > strong { font-size: 13px; }
.operation-route-heading > small { margin-left: auto; }
.operation-route-next { margin: 5px 0; }
.operation-route-next > span { margin-right: 10px; }
.operation-route-details > summary { width: fit-content; cursor: pointer; color: var(--primary, var(--app-primary, #2563eb)); }
.operation-route-details[open] { max-height: min(40dvh, 320px); overflow: auto; }
.operation-route-details[open] > summary { margin-bottom: 8px; }
.operation-route-details p { margin: 4px 0; }
.operation-route-details ol, .operation-route-details ul { margin: 6px 0; padding-left: 20px; }
.operation-route-details ol > li + li { margin-top: 10px; }
.operation-route-prerequisites, .operation-route-details li > p { color: var(--muted, var(--app-text-secondary, #627083)); }
.operation-route-follow-up { margin-top: 10px; }
.operation-route-action { padding: 4px 10px; margin: 4px 0 6px; border: 1px solid var(--line, var(--app-line, #dce2e9)); border-radius: var(--control-radius, 6px); color: var(--primary, var(--app-primary, #2563eb)); background: var(--surface, var(--app-surface, #fff)); font: inherit; cursor: pointer; }
.operation-route-action:disabled { opacity: .6; cursor: default; }
.operation-route-details > summary:focus-visible, .operation-route-action:focus-visible { outline: 2px solid var(--primary, var(--app-primary, #2563eb)); outline-offset: 2px; }
.operation-route-error { margin: 4px 0; color: var(--danger, var(--app-danger, #c9364f)); }
</style>
