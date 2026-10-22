<script setup>
// Dialog with the markup of <x-ap-modal>: focus moves in on open and back
// on close, Tab stays inside, Esc and a click outside close it.
import { nextTick, ref, useId, watch } from 'vue';
import ApIcon from './ApIcon.vue';

const props = defineProps({
	open: { type: Boolean, default: false },
	title: { type: String, required: true },
	description: { type: String, default: '' },
	icon: { type: String, default: '' },
	/** the wider dialog of .ap-modal.is-wide */
	wide: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const id = useId();
const dialog = ref(null);
let previous = null;

const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]';

watch(
	() => props.open,
	async (open) => {
		if (open) {
			previous = document.activeElement;
			await nextTick();
			const target = dialog.value?.querySelector('[data-autofocus]') ?? dialog.value?.querySelector(focusable) ?? dialog.value;
			target?.focus();
		} else if (previous instanceof HTMLElement) {
			previous.focus();
			previous = null;
		}
	},
);

function onKeydown(event) {
	if (event.key === 'Escape') {
		event.stopPropagation();
		emit('close');
		return;
	}
	if (event.key !== 'Tab' || !dialog.value) {
		return;
	}
	const items = [...dialog.value.querySelectorAll(focusable)];
	if (items.length === 0) {
		event.preventDefault();
		return;
	}
	const first = items[0];
	const last = items[items.length - 1];
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault();
		first.focus();
	}
}
</script>

<template>
	<div v-if="open" class="ap-overlay" @click.self="emit('close')" @keydown="onKeydown">
		<div
			ref="dialog"
			:class="['ap-modal', { 'is-wide': wide }]"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="`${id}-title`"
			:aria-describedby="description ? `${id}-desc` : undefined"
			tabindex="-1"
		>
			<div class="ap-modal-head">
				<span v-if="icon" class="ap-modal-icon"><ApIcon :name="icon" /></span>
				<div>
					<h2 :id="`${id}-title`">{{ title }}</h2>
					<p v-if="description" :id="`${id}-desc`">{{ description }}</p>
				</div>
			</div>
			<div class="ap-modal-body"><slot /></div>
			<div v-if="$slots.footer" class="ap-modal-foot"><slot name="footer" /></div>
		</div>
	</div>
</template>
