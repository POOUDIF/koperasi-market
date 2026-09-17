<script setup lang="ts">
import type { OrderStatus } from '@/types';
import { ORDER_STATUS } from '@/lib/format';

defineProps<{ status: OrderStatus }>();
</script>

<template>
  <span class="badge" :class="ORDER_STATUS[status].tone">{{ ORDER_STATUS[status].label }}</span>
</template>
