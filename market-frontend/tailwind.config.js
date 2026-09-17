import preset from '@jdc/ui/tailwind-preset';

/** @type {import('tailwindcss').Config} */
export default {
  presets: [preset],
  content: ['./index.html', './src/**/*.{vue,ts}', '../packages/ui/vue/**/*.vue'],
};
