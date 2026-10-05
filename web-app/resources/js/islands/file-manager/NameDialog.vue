<script setup>
// One name: new folder, new file, rename, archive name (E3 spec §7.3).
import { computed, ref, watch } from 'vue';
import ApDialog from '../shared/ApDialog.vue';
import { isValidName } from './model.js';

const props = defineProps({
	open: { type: Boolean, default: false },
	title: { type: String, required: true },
	label: { type: String, required: true },
	value: { type: String, default: '' },
	submit: { type: String, required: true },
	icon: { type: String, default: 'edit' },
	busy: { type: Boolean, default: false },
	error: { type: String, default: '' },
	/** extra rule: returns a message key or '' */
	check: { type: Function, default: () => '' },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'submit']);

const name = ref(props.value);
const touched = ref(false);
watch(
	() => [props.open, props.value],
	() => {
		name.value = props.value;
		touched.value = false;
	},
);

const problem = computed(() => {
	if (!isValidName(name.value.trim())) {
		return props.t('Enter a valid name: no slash, not . or ..');
	}
	const extra = props.check(name.value.trim());
	return extra ? props.t(extra) : '';
});

function onSubmit() {
	touched.value = true;
	if (!problem.value) {
		emit('submit', name.value.trim());
	}
}

/** Select the name without the extension, as file managers do */
function selectBase(event) {
	const input = event.target;
	const dot = input.value.lastIndexOf('.');
	input.setSelectionRange(0, dot > 0 ? dot : input.value.length);
}
</script>

<template>
	<ApDialog :open="open" :title="title" :icon="icon" @close="emit('close')">
		<form id="fm-name-form" class="ap-stack" novalidate @submit.prevent="onSubmit">
			<div class="ap-field">
				<label class="ap-label" for="fm-name">{{ label }}</label>
				<input
					id="fm-name"
					v-model="name"
					class="ap-input ap-input-mono"
					type="text"
					autocomplete="off"
					spellcheck="false"
					maxlength="255"
					data-autofocus
					:aria-invalid="(touched && problem) || error ? 'true' : undefined"
					aria-describedby="fm-name-error"
					@focus="selectBase"
				/>
				<p v-if="(touched && problem) || error" id="fm-name-error" class="ap-error">{{ error || problem }}</p>
			</div>
		</form>
		<template #footer>
			<button type="button" class="ap-btn ap-btn-ghost" @click="emit('close')">{{ t('Cancel') }}</button>
			<button type="submit" form="fm-name-form" class="ap-btn ap-btn-primary" :disabled="busy">{{ submit }}</button>
		</template>
	</ApDialog>
</template>
