import preset from '@jdc/ui/tailwind-preset';

/** @type {import('tailwindcss').Config} */
export default {
  presets: [preset],
  content: ['./**/*.html', './src/**/*.js', '!./node_modules/**'],
};
