import { defineConfig } from 'vitest/config';

export default defineConfig({
  // GitHub Pages serves the repo at /<repo>/ by default; set base when deployed there.
  base: process.env.PAGES_BASE ?? '/',
  test: { globals: true, environment: 'node' },
});
