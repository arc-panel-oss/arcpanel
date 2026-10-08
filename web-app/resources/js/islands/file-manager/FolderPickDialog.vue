<script setup>
// Destination folder of a copy or move (E3 spec §7.3): browse folders from
// the home directory down, one level at a time. A folder that cannot take
// the entries (read-only, the folder they are in, or inside a moved folder)
// says why and keeps the submit button off.
import { computed, ref, watch } from 'vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { breadcrumb, joinPath, parentPath, pickRefusal } from './model.js';

const props = defineProps({
	open: { type: Boolean, default: false },
	title: { type: String, required: true },
	submit: { type: String, required: true },
	start: { type: String, default: '' },
	/** Paths of the entries that are copied or moved */
	paths: { type: Array, default: () => [] },
	move: { type: Boolean, default: false },
	home: { type: String, required: true },
	api: { type: Object, required: true },
	busy: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'submit']);

const path = ref('');
const folders = ref([]);
const writable = ref(undefined);
const loading = ref(false);

const refusal = computed(() =>
	loading.value ? null : pickRefusal({ dest: path.value, writable: writable.value, source: props.start, paths: props.paths, move: props.move }),
);
const refusalText = computed(
	() =>
		({
			'read-only': props.t('This folder is read-only. Open a folder inside it, such as public_html.'),
			'same-folder': props.t('The items are already in this folder.'),
			inside: props.t('A folder cannot go inside itself.'),
		})[refusal.value] ?? '',
);

async function load(next) {
	loading.value = true;
	try {
		const data = await props.api.list(next, { limit: 5000 });
		folders.value = data.entries.filter((entry) => entry.dir && entry.validName).map((entry) => entry.name);
		writable.value = data.dir?.writable;
		path.value = next;
	} catch (error) {
		props.api.fail(error);
	} finally {
		loading.value = false;
	}
}

watch(
	() => props.open,
	(open) => {
		if (open) {
			load(props.start);
		}
	},
);
</script>

<template>
	<ApDialog :open="open" :title="title" icon="folder" @close="emit('close')">
		<div class="ap-stack">
			<nav class="ap-path" :aria-label="t('Destination folder')">
				<a href="#" @click.prevent="load('')"><ApIcon name="home" size="sm" /><span class="sr-only">{{ home }}</span></a>
				<template v-for="crumb in breadcrumb(path)" :key="crumb.path">
					<span>/</span>
					<a href="#" @click.prevent="load(crumb.path)">{{ crumb.name }}</a>
				</template>
			</nav>
			<div class="ap-folder-pick" role="list" :aria-busy="loading">
				<button v-if="path !== ''" type="button" role="listitem" @click="load(parentPath(path))">
					<ApIcon name="arrow-up" /><span>..</span>
				</button>
				<button v-for="name in folders" :key="name" type="button" role="listitem" @click="load(joinPath(path, name))">
					<ApIcon name="folder" class="is-dir" /><span>{{ name }}</span>
				</button>
				<p v-if="!loading && folders.length === 0" class="muted">{{ t('No folders here.') }}</p>
			</div>
			<p v-if="refusal" class="ap-folder-pick-note" role="status"><ApIcon name="lock" size="sm" /><span>{{ refusalText }}</span></p>
			<p v-else class="muted">{{ t('Into') }} <span class="mono">~/{{ path }}</span></p>
		</div>
		<template #footer>
			<button type="button" class="ap-btn ap-btn-ghost" @click="emit('close')">{{ t('Cancel') }}</button>
			<button type="button" class="ap-btn ap-btn-primary" data-autofocus :disabled="busy || loading || refusal !== null" @click="emit('submit', path)">{{ submit }}</button>
		</template>
	</ApDialog>
</template>
