<script setup>
// Names that exist in the destination (E3 spec D12): skip them, overwrite
// them or keep both ("name (1).ext").
import ApDialog from '../shared/ApDialog.vue';

defineProps({
	open: { type: Boolean, default: false },
	names: { type: Array, default: () => [] },
	busy: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'resolve']);
</script>

<template>
	<ApDialog :open="open" :title="t('Some items already exist')" icon="alert" @close="emit('close')">
		<p>{{ names.length === 1 ? t('%s already exists in the destination.', names[0]) : t('%d items already exist in the destination.', names.length) }}</p>
		<ul v-if="names.length > 1" class="mono">
			<li v-for="name in names.slice(0, 10)" :key="name">{{ name }}</li>
			<li v-if="names.length > 10">{{ t('and %d more', names.length - 10) }}</li>
		</ul>
		<template #footer>
			<button type="button" class="ap-btn ap-btn-ghost" @click="emit('close')">{{ t('Cancel') }}</button>
			<button type="button" class="ap-btn" :disabled="busy" @click="emit('resolve', 'skip')">{{ t('Skip') }}</button>
			<button type="button" class="ap-btn" data-autofocus :disabled="busy" @click="emit('resolve', 'rename')">{{ t('Keep both') }}</button>
			<button type="button" class="ap-btn ap-btn-danger" :disabled="busy" @click="emit('resolve', 'overwrite')">{{ t('Overwrite') }}</button>
		</template>
	</ApDialog>
</template>
