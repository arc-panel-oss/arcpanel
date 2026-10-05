<script setup>
// Status badge, as <x-ap-badge>: ok, warn, danger, info, accent or none.
defineProps({
	tone: { type: String, default: null },
});
</script>

<template>
	<span :class="['ap-badge', tone ? `is-${tone}` : '']"><span v-if="tone !== 'accent'" class="dot" /><slot /></span>
</template>
