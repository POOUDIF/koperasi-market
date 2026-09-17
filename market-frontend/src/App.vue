<script setup lang="ts">
import { Toaster } from 'vue-sonner';
</script>

<template>
  <router-view />
  <Toaster position="top-right" rich-colors close-button />
</template>
