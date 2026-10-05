<script setup>
// Container logs of an app (E4 spec D8): the last lines, then every 2
// seconds the lines written after the cursor of the last read, while the
// tab is open.
import { onBeforeUnmount, ref, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { appPath } from './api.js';
import { mergeLogs } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	name: { type: String, required: true },
	services: { type: Array, default: () => [] },
	active: { type: Boolean, default: false },
	t: { type: Function, required: true },
});

const t = props.t;
const service = ref('all');
const view = ref({ lines: [], next: '' });
const paused = ref(false);
let cursor = null;
let timer = null;

async function read() {
	const since = cursor;
	const query = new URLSearchParams({ service: service.value, lines: '200' });
	if (since) {
		query.set('since', since);
	}
	try {
		const answer = await props.api.call('GET', appPath(props.name, `/logs?${query}`));
		view.value = mergeLogs(view.value, answer, since);
		cursor = view.value.next || cursor;
	} catch {
		// tried again on the next round
	}
}

function schedule() {
	clearTimeout(timer);
	timer = setTimeout(async () => {
		if (props.active && !paused.value && document.visibilityState === 'visible') {
			await read();
		}
		schedule();
	}, 2000);
}

function restart() {
	view.value = { lines: [], next: '' };
	cursor = null;
	read();
	schedule();
}

async function download() {
	try {
		const query = new URLSearchParams({ service: service.value, lines: '1000' });
		const data = await props.api.call('GET', appPath(props.name, `/logs?${query}`));
		const text = data.lines.map((line) => `${line.time} ${line.service} ${line.text}`).join('\n');
		const link = document.createElement('a');
		link.href = URL.createObjectURL(new Blob([`${text}\n`], { type: 'text/plain' }));
		link.download = `${props.name}-${service.value}.log`;
		link.click();
		URL.revokeObjectURL(link.href);
	} catch (error) {
		props.api.fail(error);
	}
}

watch(service, restart);
watch(
	() => props.active,
	(active) => {
		if (active) {
			restart();
		}
	},
	{ immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
	<div class="ap-stack">
		<div class="ap-toolbar">
			<label class="ap-row">
				<span class="ap-label">{{ t('Service') }}</span>
				<select v-model="service" class="ap-input ap-input-sm">
					<option value="all">{{ t('All services') }}</option>
					<option v-for="item in services" :key="item.service" :value="item.service">{{ item.service }}</option>
				</select>
			</label>
			<div class="ap-actions">
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :aria-pressed="paused ? 'true' : 'false'" @click="paused = !paused">
					<ApIcon :name="paused ? 'play' : 'pause'" />{{ paused ? t('Resume') : t('Pause') }}
				</button>
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="view = { lines: [], next: view.next }">
					<ApIcon name="x" />{{ t('Clear the view') }}
				</button>
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="download"><ApIcon name="download" />{{ t('Download') }}</button>
			</div>
		</div>
		<div class="ap-log">
			<div class="ap-log-body" tabindex="0" role="log" aria-live="off" :aria-label="t('Logs')">
				<div v-if="view.lines.length === 0" class="ap-log-line muted">{{ t('Nothing logged yet.') }}</div>
				<div v-for="(line, index) in view.lines" :key="index" class="ap-log-line">
					<span class="muted">{{ line.time.slice(11, 19) }}</span>
					<span v-if="services.length > 1" class="muted"> {{ line.service }}</span>
					{{ line.text }}
				</div>
			</div>
		</div>
	</div>
</template>
