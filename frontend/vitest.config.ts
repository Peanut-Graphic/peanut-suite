import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.{test,spec,a11y}.{js,ts,jsx,tsx}'],
    setupFiles: ['src/test/setup.ts'],
  },
});
