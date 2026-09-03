<script setup lang="ts">
import { statusBadgeClass, statusLabel } from '@/lib/utils';

const props = defineProps<{ status: string }>();
</script>

<template>
  <span class="badge" :class="statusBadgeClass(props.status)">{{ statusLabel(props.status) }}</span>
</template>
