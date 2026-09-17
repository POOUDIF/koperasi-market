import preset from '@jdc/ui/tailwind-preset';

/** @type {import('tailwindcss').Config} */
export default {
  // Warna/tipografi dari logo Jawa Dwipa Cooperative — sumber tunggal di packages/ui.
  presets: [preset],
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}', '../packages/ui/vue/**/*.vue'],
};
