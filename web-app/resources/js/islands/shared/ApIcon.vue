<script setup>
// Icon from the page's sprite, as <x-ap-icon>.
defineProps({
	name: { type: String, required: true },
	size: { type: String, default: '' },
});
</script>

<template>
	<svg :class="['ic', size ? `ic-${size}` : '']" aria-hidden="true"><use :href="`#i-${name}`" /></svg>
</template>
