<script setup>
// The upload queue (E3 spec §7.4): progress per file and overall, cancel,
// resume, and the answer to a name that exists already.
import { computed, ref } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { formatSize } from './model.js';

const props = defineProps({
	uploader: { type: Object, required: true },
	/** bumped by the parent on every change of an item */
	tick: { type: Number, default: 0 },
	maxBytes: { type: Number, required: true },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const forAll = ref(false);
const items = computed(() => {
	props.tick;
	return [...props.uploader.items];
});
const totals = computed(() => {
	props.tick;
	return props.uploader.totals();
});
const running = computed(() => items.value.some((item) => ['queued', 'uploading'].includes(item.state)));
const percent = computed(() => (totals.value.size > 0 ? Math.round((totals.value.sent / totals.value.size) * 100) : 0));

function itemPercent(item) {
	return item.size > 0 ? Math.round((item.sent / item.size) * 100) : item.state === 'done' ? 100 : 0;
}

function icon(item) {
	return { done: 'check-circle', error: 'x-circle', cancelled: 'x', conflict: 'alert' }[item.state] ?? 'upload';
}

function tone(item) {
	return { done: 'is-ok', error: 'is-danger', conflict: 'is-warn' }[item.state] ?? '';
}

function status(item) {
	switch (item.state) {
		case 'queued':
			return props.t('Waiting');
		case 'uploading':
			return `${formatSize(item.sent)} / ${formatSize(item.size)}`;
		case 'done':
			return item.landed && item.landed !== item.name ? props.t('Uploaded as %s', item.landed) : props.t('Uploaded');
		case 'cancelled':
			return props.t('Cancelled');
		case 'conflict':
			return props.t('A file with this name exists');
		default:
			return item.error === 'too-large' ? props.t('Larger than the limit of %s', formatSize(props.maxBytes)) : item.error;
	}
}

function close() {
	props.uploader.clearFinished();
	emit('close');
}
</script>

<template>
	<section class="ap-card ap-card-flush" :aria-label="t('Uploads')">
		<div class="ap-card-head">
			<div class="ap-card-title">
				<ApIcon name="upload" />
				<h2>{{ t('Uploads') }}</h2>
				<span class="muted mono">{{ percent }}% · {{ formatSize(totals.sent) }} / {{ formatSize(totals.size) }}</span>
			</div>
			<div class="ap-actions">
				<button v-if="running" type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="uploader.cancelAll()"><ApIcon name="x" />{{ t('Cancel all') }}</button>
				<button v-else type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="close"><ApIcon name="check" />{{ t('Done') }}</button>
			</div>
		</div>
		<span class="ap-bar" role="progressbar" :aria-label="t('Upload progress')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent">
			<span :style="{ width: `${percent}%` }"></span>
		</span>
		<ul class="ap-upload-list">
			<li v-for="item in items" :key="item.id">
				<div class="ap-upload-row">
					<ApIcon :name="icon(item)" :class="tone(item)" />
					<div class="ap-cell-main">
						<b>{{ item.relativePath || item.name }}</b>
						<span>{{ status(item) }}</span>
					</div>
					<button v-if="item.state === 'error' && item.error !== 'too-large'" type="button" class="ap-btn ap-btn-sm" @click="uploader.retry(item)">
						<ApIcon name="refresh" />{{ t('Resume') }}
					</button>
					<button
						v-if="['queued', 'uploading', 'conflict'].includes(item.state)"
						type="button"
						class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm"
						:aria-label="t('Cancel %s', item.name)"
						@click="uploader.cancel(item)"
					>
						<ApIcon name="x" />
					</button>
				</div>
				<span v-if="item.state === 'uploading'" class="ap-bar" role="progressbar" :aria-label="t('Progress of %s', item.name)" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="itemPercent(item)">
					<span :style="{ width: `${itemPercent(item)}%` }"></span>
				</span>
				<div v-if="item.state === 'conflict'" class="ap-actions">
					<button type="button" class="ap-btn ap-btn-sm" @click="uploader.resolve(item, 'skip', forAll)">{{ t('Skip') }}</button>
					<button type="button" class="ap-btn ap-btn-sm" @click="uploader.resolve(item, 'rename', forAll)">{{ t('Keep both') }}</button>
					<button type="button" class="ap-btn ap-btn-danger ap-btn-sm" @click="uploader.resolve(item, 'overwrite', forAll)">{{ t('Overwrite') }}</button>
					<label class="ap-check"><input v-model="forAll" type="checkbox" /><span>{{ t('Apply to all') }}</span></label>
				</div>
			</li>
		</ul>
	</section>
</template>
