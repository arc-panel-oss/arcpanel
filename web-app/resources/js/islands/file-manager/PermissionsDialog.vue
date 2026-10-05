<script setup>
// Permissions of the selection (E3 spec §7.3): octal field and rwx grid;
// for folders "apply to contents" with separate folder and file modes.
import { computed, ref, watch } from 'vue';
import ApDialog from '../shared/ApDialog.vue';
import { bitsMode, isValidMode, modeBits, modeString } from './model.js';

const props = defineProps({
	open: { type: Boolean, default: false },
	entries: { type: Array, default: () => [] },
	busy: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'submit']);

const mode = ref('0644');
const recursive = ref(false);
const filesMode = ref('0644');

const hasFolder = computed(() => props.entries.some((entry) => entry.type === 'd'));
const title = computed(() =>
	props.entries.length === 1 ? props.t('Permissions of %s', props.entries[0].name) : props.t('Permissions of %d items', props.entries.length),
);
const bits = computed(() => modeBits(mode.value));
const valid = computed(() => isValidMode(mode.value) && (!recursive.value || isValidMode(filesMode.value)));

watch(
	() => props.open,
	(open) => {
		if (open) {
			const first = props.entries[0];
			mode.value = first?.mode && isValidMode(first.mode) ? first.mode : hasFolder.value ? '0755' : '0644';
			recursive.value = false;
			filesMode.value = '0644';
		}
	},
);

function toggle(who, bit) {
	const next = modeBits(mode.value);
	next[who][bit] = !next[who][bit];
	mode.value = bitsMode(next, mode.value);
}

function submit() {
	if (valid.value) {
		emit('submit', { mode: mode.value, recursive: hasFolder.value && recursive.value, filesMode: recursive.value ? filesMode.value : null });
	}
}

const rows = [
	['owner', 'Owner'],
	['group', 'Group'],
	['other', 'Others'],
];
</script>

<template>
	<ApDialog :open="open" :title="title" icon="lock" @close="emit('close')">
		<form id="fm-perm-form" class="ap-stack" novalidate @submit.prevent="submit">
			<table class="ap-perm">
				<thead>
					<tr>
						<th scope="col"><span class="sr-only">{{ t('Who') }}</span></th>
						<th scope="col">{{ t('Read') }}</th>
						<th scope="col">{{ t('Write') }}</th>
						<th scope="col">{{ t('Execute') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="[who, label] in rows" :key="who">
						<th scope="row">{{ t(label) }}</th>
						<td v-for="bit in ['r', 'w', 'x']" :key="bit">
							<label class="ap-check">
								<input type="checkbox" :checked="bits[who][bit]" :aria-label="`${t(label)} ${bit}`" @change="toggle(who, bit)" />
							</label>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="ap-field">
				<label class="ap-label" for="fm-mode">{{ hasFolder && recursive ? t('Folder mode') : t('Mode') }}</label>
				<input id="fm-mode" v-model="mode" class="ap-input ap-input-mono" inputmode="numeric" maxlength="4" data-autofocus :aria-invalid="isValidMode(mode) ? undefined : 'true'" />
				<p class="ap-hint mono">{{ modeString(mode) }}</p>
			</div>
			<template v-if="hasFolder">
				<label class="ap-check"><input v-model="recursive" type="checkbox" /><span>{{ t('Apply to everything inside the folder') }}</span></label>
				<div v-if="recursive" class="ap-field">
					<label class="ap-label" for="fm-files-mode">{{ t('File mode') }}</label>
					<input id="fm-files-mode" v-model="filesMode" class="ap-input ap-input-mono" inputmode="numeric" maxlength="4" :aria-invalid="isValidMode(filesMode) ? undefined : 'true'" />
					<p class="ap-hint">{{ t('Folders get the folder mode, files the file mode. Links are left as they are.') }}</p>
				</div>
			</template>
		</form>
		<template #footer>
			<button type="button" class="ap-btn ap-btn-ghost" @click="emit('close')">{{ t('Cancel') }}</button>
			<button type="submit" form="fm-perm-form" class="ap-btn ap-btn-primary" :disabled="busy || !valid">{{ t('Apply') }}</button>
		</template>
	</ApDialog>
</template>
