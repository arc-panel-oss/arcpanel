<script setup>
// Logs of an app (E14 spec D7): the last lines, then every 2 seconds what
// was written after the offset of the last read, while the tab is open.
import { onBeforeUnmount, ref, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { mergeLog } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	domain: { type: String, required: true },
	active: { type: Boolean, default: false },
	t: { type: Function, required: true },
	// App processes (E42) reuse the view with their own path and streams
	path: { type: String, default: '' },
	streams: { type: Array, default: null },
	name: { type: String, default: '' },
});

const t = props.t;
const streams = props.streams ?? [
	['out', t('Output')],
	['err', t('Errors')],
	['install', t('Install')],
	['build', t('Build')],
];
const stream = ref(streams[0][0]);
const view = ref({ lines: [], offset: null });
const paused = ref(false);
let timer = null;

const path = () => props.path || `/${encodeURIComponent(props.domain)}/logs`;

async function read() {
	const asked = view.value.offset;
	const query = asked === null ? `stream=${stream.value}&lines=200` : `stream=${stream.value}&offset=${asked}`;
	try {
		view.value = mergeLog(view.value, await props.api.call('GET', `${path()}?${query}`), asked);
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
	view.value = { lines: [], offset: null };
	read();
	schedule();
}

async function download() {
	try {
		const data = await props.api.call('GET', `${path()}?stream=${stream.value}&lines=1000`);
		const blob = new Blob([`${data.lines.join('\n')}\n`], { type: 'text/plain' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = `${props.domain}${props.name ? `-${props.name}` : ''}-${stream.value}.log`;
		link.click();
		URL.revokeObjectURL(link.href);
	} catch (error) {
		props.api.fail(error);
	}
}

watch(stream, restart);
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
			<div class="ap-row" role="group" :aria-label="t('Log')">
				<button
					v-for="[name, label] in streams"
					:key="name"
					type="button"
					class="ap-btn ap-btn-sm"
					:class="stream === name ? 'ap-btn-primary' : 'ap-btn-ghost'"
					:aria-pressed="stream === name ? 'true' : 'false'"
					@click="stream = name"
				>
					{{ label }}
				</button>
			</div>
			<div class="ap-actions">
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :aria-pressed="paused ? 'true' : 'false'" @click="paused = !paused">
					<ApIcon :name="paused ? 'play' : 'pause'" />{{ paused ? t('Resume') : t('Pause') }}
				</button>
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="view = { lines: [], offset: view.offset }">
					<ApIcon name="x" />{{ t('Clear the view') }}
				</button>
				<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="download"><ApIcon name="download" />{{ t('Download') }}</button>
			</div>
		</div>
		<div class="ap-log">
			<div class="ap-log-body" tabindex="0" role="log" aria-live="off" :aria-label="t('Log')">
				<div v-if="view.lines.length === 0" class="ap-log-line muted">{{ t('Nothing logged yet.') }}</div>
				<div v-for="(line, index) in view.lines" :key="index" class="ap-log-line">{{ line }}</div>
			</div>
		</div>
	</div>
</template>
