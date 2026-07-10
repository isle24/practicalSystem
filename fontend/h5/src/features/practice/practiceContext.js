import { inject, provide } from 'vue';

const practiceContextKey = Symbol('practice-context');

export function providePracticeContext(controller) {
  provide(practiceContextKey, controller);
  return controller;
}

export function usePracticeContext() {
  const controller = inject(practiceContextKey);
  if (!controller) {
    throw new Error('Practice context is unavailable');
  }
  return controller;
}
