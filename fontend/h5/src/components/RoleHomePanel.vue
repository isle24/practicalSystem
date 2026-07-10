<template>
  <section class="role-home-panel">
    <header class="role-home-identity">
      <span><UserRound :size="23" /></span>
      <div>
        <small>{{ schoolName }}</small>
        <strong>{{ userName }}</strong>
        <p>{{ roleName }} · {{ scopeText }}</p>
      </div>
    </header>
    <div class="role-home-section-head">
      <div>
        <strong>{{ sectionTitle }}</strong>
        <small>{{ sectionDescription }}</small>
      </div>
    </div>
    <div class="role-home-metrics">
      <button v-for="item in items" :key="item.title" type="button" @click="emit('open', item)">
        <span>{{ item.value }}</span>
        <strong>{{ item.title }}</strong>
        <small>{{ item.label }}</small>
      </button>
    </div>
  </section>
</template>

<script setup>
import { UserRound } from '@lucide/vue';

defineProps({
  schoolName: { type: String, default: '' },
  userName: { type: String, default: '' },
  roleName: { type: String, default: '' },
  scopeText: { type: String, default: '' },
  sectionTitle: { type: String, default: '' },
  sectionDescription: { type: String, default: '' },
  items: { type: Array, default: () => [] },
});

const emit = defineEmits(['open']);
</script>
