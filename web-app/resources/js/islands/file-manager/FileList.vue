<script setup>
// Entries of the current folder as a table or as tiles (E3 spec §7.2).
// Rows render in steps of 200 as the list scrolls; the parent pages large
// folders from the server ("more").
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { extension, formatSize, formatTime, iconFor } from './model.js';

const props = defineProps({
	entries: { type: Array, required: true },
	view: { type: String, default: 'list' },
	selected: { type: Array, default: () => [] },
	cut: { type: Array, default: () => [] },
	sort: { type: Object, required: true },
	total: { type: Number, default: 0 },
	t: { type: Function, required: true },
	entryUrl: { type: Function, required: true },
	label: { type: String, required: true },
});
const emit = defineEmits(['open', 'select', 'menu', 'sort', 'toggle', 'toggle-all', 'more']);

const STEP = 200;
const shown = ref(STEP);
const sentinel = ref(null);
let observer = null;

const visible = computed(() => props.entries.slice(0, shown.value));
const selectedSet = computed(() => new Set(props.selected));
const cutSet = computed(() => new Set(props.cut));
const allSelected = computed(() => props.entries.length > 0 && props.selected.length === props.entries.length);

watch(
	() => props.entries,
	(next, previous) => {
		if (next !== previous && next.length < shown.value) {
			shown.value = STEP;
		}
	},
);

onMounted(() => {
	if (typeof IntersectionObserver === 'undefined') {
		return;
	}
	observer = new IntersectionObserver((items) => {
		if (!items.some((item) => item.isIntersecting)) {
			return;
		}
		if (shown.value < props.entries.length) {
			shown.value += STEP;
		} else if (props.entries.length < props.total) {
			emit('more');
		}
	});
	watch(
		sentinel,
		(element, old) => {
			if (old) observer.unobserve(old);
			if (element) observer.observe(element);
		},
		{ immediate: true },
	);
});
onBeforeUnmount(() => observer?.disconnect());

function sortState(key) {
	if (props.sort.key !== key) {
		return 'none';
	}
	return props.sort.order === 'desc' ? 'descending' : 'ascending';
}

function click(event, entry) {
	emit('select', entry.name, { ctrl: event.ctrlKey || event.metaKey, shift: event.shiftKey });
}

function open(event, entry) {
	if (entry.dir && (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1)) {
		return;
	}
	event.preventDefault();
	emit('open', entry);
}

function sizeLabel(entry) {
	return entry.dir ? '—' : formatSize(entry.size);
}
</script>

<template>
	<div v-if="view === 'grid'" class="ap-file-grid" role="listbox" :aria-label="label" aria-multiselectable="true">
		<button
			v-for="entry in visible"
			:key="entry.name"
			type="button"
			role="option"
			:aria-selected="selectedSet.has(entry.name)"
			:class="['ap-tile', { 'is-selected': selectedSet.has(entry.name), 'is-cut': cutSet.has(entry.name) }]"
			:title="entry.name"
			@click="click($event, entry)"
			@dblclick="open($event, entry)"
			@contextmenu.prevent="emit('menu', $event, entry)"
		>
			<span :class="['ap-thumb', { 'is-dir': entry.dir }]">
				<ApIcon :name="iconFor(entry)" />
				<span v-if="!entry.dir && extension(entry.name)" class="ext">{{ extension(entry.name).toUpperCase() }}</span>
			</span>
			<b>{{ entry.name }}</b>
			<span>{{ entry.dir ? t('Folder') : formatSize(entry.size) }}</span>
		</button>
		<div v-if="entries.length > shown || entries.length < total" ref="sentinel" class="ap-fm-more">{{ t('Loading…') }}</div>
	</div>

	<div v-else class="ap-table-wrap">
		<table class="ap-table ap-table-cards">
			<caption class="sr-only">{{ label }}</caption>
			<thead>
				<tr>
					<th scope="col" class="col-check">
						<label class="ap-check"><input type="checkbox" :checked="allSelected" :aria-label="t('Select all')" @change="emit('toggle-all')" /></label>
					</th>
					<th scope="col" :aria-sort="sortState('name')">
						<button type="button" @click="emit('sort', 'name')">{{ t('Name') }}<ApIcon name="updown" size="sm" /></button>
					</th>
					<th scope="col" class="col-num" :aria-sort="sortState('size')">
						<button type="button" @click="emit('sort', 'size')">{{ t('Size') }}<ApIcon name="updown" size="sm" /></button>
					</th>
					<th scope="col" :aria-sort="sortState('mtime')">
						<button type="button" @click="emit('sort', 'mtime')">{{ t('Modified') }}<ApIcon name="updown" size="sm" /></button>
					</th>
					<th scope="col">{{ t('Permissions') }}</th>
					<th scope="col">{{ t('Owner') }}</th>
					<th scope="col" class="col-actions"><span class="sr-only">{{ t('Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="entry in visible"
					:key="entry.name"
					:class="{ 'is-selected': selectedSet.has(entry.name), 'is-cut': cutSet.has(entry.name) }"
					@click="click($event, entry)"
					@dblclick="open($event, entry)"
					@contextmenu.prevent="emit('menu', $event, entry)"
				>
					<td class="col-check" @click.stop>
						<label class="ap-check">
							<input type="checkbox" :checked="selectedSet.has(entry.name)" :aria-label="t('Select %s', entry.name)" @change="emit('toggle', entry.name)" />
						</label>
					</td>
					<td :data-label="t('Name')">
						<a
							:href="entryUrl(entry)"
							:class="['ap-file', { 'is-dir': entry.dir }]"
							@click.stop="open($event, entry)"
						>
							<ApIcon :name="iconFor(entry)" />
							<span :class="{ mono: !entry.dir }">{{ entry.name }}</span>
							<span v-if="entry.type === 'l'" class="link">→ {{ entry.link }}</span>
							<span v-if="entry.type === 'l' && !entry.linkInside" class="ap-badge is-warn">{{ t('outside home') }}</span>
						</a>
					</td>
					<td :data-label="t('Size')" class="col-num"><span class="mono">{{ sizeLabel(entry) }}</span></td>
					<td :data-label="t('Modified')" class="nowrap"><span class="muted">{{ formatTime(entry.mtime) }}</span></td>
					<td :data-label="t('Permissions')"><span class="mono">{{ entry.mode }}</span></td>
					<td :data-label="t('Owner')"><span class="mono muted">{{ entry.owner }}</span></td>
					<td class="col-actions" @click.stop>
						<button
							type="button"
							class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm"
							:aria-label="t('Actions for %s', entry.name)"
							@click="emit('menu', $event, entry)"
						>
							<ApIcon name="more" />
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<div v-if="entries.length > shown || entries.length < total" ref="sentinel" class="ap-fm-more">{{ t('Loading…') }}</div>
	</div>
</template>
