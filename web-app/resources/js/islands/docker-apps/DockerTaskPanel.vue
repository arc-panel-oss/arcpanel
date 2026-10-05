<script setup>
// A task of a Docker app or of the engine (install, update, rollback,
// snapshot): its state and its output, read after a byte offset every 2
// seconds while it runs.
import { onBeforeUnmount, ref, watch } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import { createTaskPoller } from '../shared/task-poller.js';
import { mergeTask } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	/** GET path of the task, without ?offset */
	path: { type: String, required: true },
	task: { type: Object, required: true },
	label: { type: String, required: true },
	t: { type: Function, required: true },
});
const emit = defineEmits(['finish']);

const t = props.t;
const current = ref({ ...props.task });
const open = ref(Boolean(props.task.running));

const poller = createTaskPoller({
	fetchTask: async () => {
		const offset = Number(current.value.offset) || 0;
		const answer = (await props.api.call('GET', `${props.path}?offset=${offset}`)).task;
		current.value = mergeTask(current.value, answer, offset);
		return current.value;
	},
	onFinish: (task) => emit('finish', task),
	onError: () => {},
});

function toggle() {
	open.value = !open.value;
	if (open.value && !current.value.running) {
		load();
	}
}

async function load() {
	try {
		current.value = (await props.api.call('GET', `${props.path}?offset=0`)).task;
	} catch {
		// shown as it came with the page
	}
}

watch(
	() => props.task.id,
	async () => {
		current.value = { ...props.task };
		if (props.task.running) {
			open.value = true;
			await load();
			poller.start();
		}
	},
	{ immediate: true },
);
onBeforeUnmount(() => poller.stop());
</script>

<template>
	<div v-if="current.id" class="ap-stack" aria-live="polite">
		<div class="ap-between">
			<b>{{ label }}</b>
			<div class="ap-actions">
				<ApBadge :tone="current.running ? 'info' : current.status === 'done' ? 'ok' : 'danger'">
					<span v-if="current.running" class="ap-spinner" aria-hidden="true" />
					{{ current.running ? t('Running') : current.status === 'done' ? t('Done') : t('Failed') }}
				</ApBadge>
				<button
					type="button"
					class="ap-btn ap-btn-ghost ap-btn-sm"
					:aria-expanded="open ? 'true' : 'false'"
					@click="toggle"
				>
					{{ open ? t('Hide the output') : t('Show the output') }}
				</button>
			</div>
		</div>
		<div v-if="open" class="ap-log">
			<div class="ap-log-body" tabindex="0" role="log" :aria-label="label">
				<div v-for="(line, index) in (current.log || '').split('\n')" :key="index" class="ap-log-line">{{ line }}</div>
			</div>
		</div>
	</div>
</template>
