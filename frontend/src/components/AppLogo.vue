<script setup lang="ts">
// Lencana bergaya emblem, terinspirasi logo Jawa Dwipa Cooperative:
// cincin hijau, siluet gapura coklat di atas, monogram "JD", aksen daun.
// (Rekreasi bergaya — bukan reproduksi piksel dari file logo asli. Jika file
// logo resmi tersedia sebagai gambar, taruh di public/logo.png dan ganti
// pemakaian <AppLogo /> dengan <img src="/logo.png" /> pada tempat yang perlu.)
withDefaults(defineProps<{ size?: number }>(), { size: 40 });
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <circle cx="32" cy="32" r="30" class="fill-primary-700" />
    <circle cx="32" cy="32" r="30" fill="none" stroke="#C9971F" stroke-width="1.5" opacity="0.5" />
    <path
      d="M22 22 L22 14 Q22 10 26 10 L26 16 Q26 19 29 19 L29 12 L35 12 L35 19 Q38 19 38 16 L38 10 Q42 10 42 14 L42 22"
      fill="none"
      stroke="#E6B94D"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
    <text
      x="32"
      y="42"
      text-anchor="middle"
      font-family="'Playfair Display', Georgia, serif"
      font-weight="700"
      font-size="20"
      fill="#FBF7EE"
    >JD</text>
    <path d="M20 48 Q32 54 44 48" fill="none" stroke="#6FB38D" stroke-width="2" stroke-linecap="round" />
  </svg>
</template>
