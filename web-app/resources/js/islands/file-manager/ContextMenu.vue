<script setup>
// Context menu at the pointer (or under a button): arrow keys move, Enter
// picks, Esc and a click elsewhere close it, focus goes back where it was.
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ApIcon from '../shared/ApIcon.vue';

const props = defineProps({
	x: { type: Number, required: true },
	y: { type: Number, required: true },
	/** [{action, label, icon, danger?, kbd?} | {separator: true}] */
	items: { type: Array, required: true },
	label: { type: String, required: true },
});
const emit = defineEmits(['pick', 'close']);

const menu = ref(null);
const position = ref({ left: `${props.x}px`, top: `${props.y}px` });
const previous = document.activeElement;

function buttons() {
	return [...(menu.value?.querySelectorAll('button') ?? [])];
}

function onKeydown(event) {
	const items = buttons();
	const index = items.indexOf(document.activeElement);
	if (event.key === 'Escape') {
		event.preventDefault();
		close();
	} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
		event.preventDefault();
		const step = event.key === 'ArrowDown' ? 1 : -1;
		items[(index + step + items.length) % items.length]?.focus();
	} else if (event.key === 'Tab') {
		close();
	}
}

function outside(event) {
	if (menu.value && !menu.value.contains(event.target)) {
		close();
	}
}

function close() {
	emit('close');
	if (previous instanceof HTMLElement) {
		previous.focus();
	}
}

function pick(action) {
	emit('pick', action);
	emit('close');
}

onMounted(async () => {
	await nextTick();
	const rect = menu.value?.getBoundingClientRect();
	if (rect) {
		const left = Math.max(8, Math.min(props.x, window.innerWidth - rect.width - 8));
		const top = props.y + rect.height > window.innerHeight - 8 ? Math.max(8, props.y - rect.height) : props.y;
		position.value = { left: `${left}px`, top: `${top}px` };
	}
	buttons()[0]?.focus();
	document.addEventListener('mousedown', outside, true);
});
onBeforeUnmount(() => document.removeEventListener('mousedown', outside, true));
</script>

<template>
	<div ref="menu" class="ap-menu ap-context-menu" role="menu" :aria-label="label" :style="position" @keydown="onKeydown">
		<template v-for="(item, index) in items" :key="item.action ?? `sep-${index}`">
			<div v-if="item.separator" class="ap-menu-sep" role="separator"></div>
			<button
				v-else
				type="button"
				role="menuitem"
				:class="['ap-menu-item', { 'is-danger': item.danger }]"
				@click="pick(item.action)"
			>
				<ApIcon :name="item.icon" />
				<span>{{ item.label }}</span>
				<span v-if="item.kbd" class="ap-kbd">{{ item.kbd }}</span>
			</button>
		</template>
	</div>
</template>
