<template>
  <RoleHomePanel
    :school-name="schoolName"
    :user-name="userName"
    :role-name="roleName"
    :scope-text="scopeText"
    :section-title="sectionTitle"
    :section-description="sectionDescription"
    :items="items"
    @open="emit('open-focus', $event)"
  />

  <section v-if="supportModules.length" class="home-service-section">
    <header>
      <strong>常用服务</strong>
      <small>制度、流程和材料下载</small>
    </header>
    <div class="home-service-list">
      <button
        v-for="module in supportModules"
        :key="module.key"
        type="button"
        @click="emit('open-tab', module.key)"
      >
        <span :class="module.theme">
          <component :is="module.icon" :size="21" />
        </span>
        <div>
          <strong>{{ module.title }}</strong>
          <small>{{ module.desc }}</small>
        </div>
        <ChevronRight :size="18" />
      </button>
    </div>
  </section>

  <section v-if="student" class="student-guide-card">
    <header>
      <Route :size="20" />
      <strong>实习流程</strong>
    </header>
    <div class="student-guide-steps">
      <span v-for="step in flowSteps" :key="step">{{ step }}</span>
    </div>
  </section>
</template>

<script setup>
import { ChevronRight, Route } from '@lucide/vue';
import RoleHomePanel from '../components/RoleHomePanel.vue';

defineProps({
  schoolName: { type: String, default: '' },
  userName: { type: String, default: '' },
  roleName: { type: String, default: '' },
  scopeText: { type: String, default: '' },
  sectionTitle: { type: String, default: '' },
  sectionDescription: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  supportModules: { type: Array, default: () => [] },
  student: { type: Boolean, default: false },
  flowSteps: { type: Array, default: () => [] },
});

const emit = defineEmits(['open-focus', 'open-tab']);
</script>

<style scoped>
.home-service-list button {
  transform: scale(1);
  transition: background var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.home-service-list button:active {
  background: var(--app-surface-pressed);
  transform: scale(.99);
}
</style>
