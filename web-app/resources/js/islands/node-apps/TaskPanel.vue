<script setup>
// The current or last task of the account (install, build), as on the
// Node.js page: what it does, its state and the end of its output.
import { ref, watch } from 'vue';
import { taskLabel } from '../node-manager/model.js';
import ApBadge from '../shared/ApBadge.vue';

const props = defineProps({
	task: { type: Object, required: true },
	t: { type: Function, required: true },
});

const open = ref(Boolean(props.task.running));
watch(
	() => props.task.running,
	(running) => {
		if (running) {
			open.value = true;
		}
	},
);
</script>

<template>
	<div class="ap-stack" aria-live="polite">
		<div class="ap-between">
			<b>{{ taskLabel(task, t) }}</b>
			<div class="ap-actions">
				<ApBadge :tone="task.running ? 'info' : task.status === 'done' ? 'ok' : 'danger'">
					<span v-if="task.running" class="ap-spinner" aria-hidden="true" />
					{{ task.running ? t('Running') : task.status === 'done' ? t('Done') : t('Failed') }}
				</ApBadge>
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :aria-expanded="open ? 'true' : 'false'" @click="open = !open">
					{{ open ? t('Hide the output') : t('Show the output') }}
				</button>
			</div>
		</div>
		<div v-if="open" class="ap-log">
			<div class="ap-log-body" tabindex="0" role="log" :aria-label="taskLabel(task, t)">
				<div v-for="(line, index) in (task.log || '').split('\n')" :key="index" class="ap-log-line">{{ line }}</div>
			</div>
		</div>
	</div>
</template>
