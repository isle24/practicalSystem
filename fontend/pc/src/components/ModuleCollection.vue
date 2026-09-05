<template>
  <section class="module-collection">
    <header>
      <div>
        <h1>{{ title }}</h1>
        <p>{{ description }}</p>
      </div>
      <span>{{ items.length }} 个应用</span>
    </header>
    <div class="module-collection-grid">
      <ShortcutTile
        v-for="item in items"
        :key="item.id"
        :item="item"
        mode="collection"
        :backend-url="backendUrl"
        @open="emit('open', item)"
      />
    </div>
  </section>
</template>

<script setup>
import ShortcutTile from './ShortcutTile.vue';

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['open']);
</script>
