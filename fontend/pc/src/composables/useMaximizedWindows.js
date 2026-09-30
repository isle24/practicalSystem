import { computed, onBeforeUnmount, reactive } from 'vue';

const maximizedWindows = reactive(new Set());
export const hasMaximizedWindow = computed(() => maximizedWindows.size > 0);

export function useMaximizedWindow() {
  const id = Symbol('window');
  const register = () => maximizedWindows.add(id);
  const unregister = () => maximizedWindows.delete(id);
  onBeforeUnmount(unregister);
  return { register, unregister };
}
