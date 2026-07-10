import { inject, provide } from 'vue';

const internshipContextKey = Symbol('internship-context');

export function provideInternshipContext(controller) {
  provide(internshipContextKey, controller);
  return controller;
}

export function useInternshipContext() {
  const controller = inject(internshipContextKey);
  if (!controller) {
    throw new Error('Internship context is unavailable');
  }
  return controller;
}
