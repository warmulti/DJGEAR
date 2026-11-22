import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['assets/src/scripts/__tests__/**/*.test.ts'],
  },
});
