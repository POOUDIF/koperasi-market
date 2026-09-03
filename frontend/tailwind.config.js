/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        // Palet diambil dari logo Jawa Dwipa Cooperative:
        // hijau tua (padi/daun), coklat tua (gapura/tangan), emas (kapas/padi).
        primary: {
          50: '#EAF3EE',
          100: '#CDE4D7',
          200: '#A0CDB4',
          300: '#6FB38D',
          400: '#439969',
          500: '#2A7A4E',
          600: '#1F5F3C',
          700: '#1B4332',
          800: '#143327',
          900: '#0E241C',
        },
        secondary: {
          50: '#FAF2EC',
          100: '#F0DCC9',
          200: '#DEB48C',
          300: '#C68C57',
          400: '#A56A3A',
          500: '#855430',
          600: '#6B3E26',
          700: '#542F1D',
          800: '#3D2215',
          900: '#26150D',
        },
        gold: {
          50: '#FDF8EC',
          100: '#F9EAC4',
          200: '#F1D488',
          300: '#E6B94D',
          400: '#D9A62E',
          500: '#C9971F',
          600: '#A87A18',
          700: '#866113',
          800: '#634A0F',
          900: '#40300A',
        },
        cream: {
          DEFAULT: '#FBF7EE',
          50: '#FFFDFA',
          100: '#FBF7EE',
          200: '#F4EBD8',
        },
      },
      fontFamily: {
        display: ['"Playfair Display"', 'Georgia', 'serif'],
        sans: ['"Inter"', 'system-ui', '-apple-system', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 2px 0 rgba(27, 67, 50, 0.06), 0 1px 6px -1px rgba(27, 67, 50, 0.08)',
        'card-hover': '0 4px 12px -2px rgba(27, 67, 50, 0.12), 0 2px 6px -2px rgba(27, 67, 50, 0.08)',
      },
      borderRadius: {
        xl2: '1rem',
      },
    },
  },
  plugins: [],
};
