<script setup>
import TeachingPlanCenterView from '../../../shared/components/TeachingPlanCenterView.vue';
import { request } from '../api/client';
defineProps({ canImport: Boolean, canAssign: Boolean, canChange: Boolean, sessionKey: { type: String, default: '' } });
const emit = defineEmits(['open-target']);
</script>
<template>
  <TeachingPlanCenterView :request="request" :can-import="canImport" :can-assign="canAssign" :can-change="canChange" :session-key="sessionKey" @open-target="emit('open-target', $event)" />
</template>
