import { defineConfig } from 'vitest/config';

export default defineConfig({
  // GitHub Pages serves the repo at /<repo>/ by default; set base when deployed there.
  base: process.env.PAGES_BASE ?? '/',
  // Scope to our own suite so Vitest's default glob doesn't descend into the
  // PHP build's vendor/ tree (web/build/vendor) and pick up bundled JS tests.
  test: { globals: true, environment: 'node', include: ['test/**/*.test.ts'] },
});
