<script setup>
// Folder tree of the file manager (E3 spec §7.2): folders load when they
// open; the folders above the current one open by themselves. `version`
// changes after operations, so open folders read again.
import { computed, reactive, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { isInside, joinPath, segments } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	current: { type: String, default: '' },
	hidden: { type: Boolean, default: true },
	home: { type: String, required: true },
	pageUrl: { type: Function, required: true },
	version: { type: Number, default: 0 },
	label: { type: String, required: true },
});
const emit = defineEmits(['navigate']);

/** path → {children: string[]|null, open: boolean, loading: boolean} */
const nodes = reactive({ '': { children: null, open: true, loading: false } });

function node(path) {
	nodes[path] ??= { children: null, open: false, loading: false };
	return nodes[path];
}

async function load(path) {
	const item = node(path);
	item.loading = true;
	try {
		const data = await props.api.list(path, { limit: 5000, hidden: props.hidden });
		item.children = data.entries.filter((entry) => entry.dir && entry.validName).map((entry) => entry.name);
	} catch {
		item.children = [];
	} finally {
		item.loading = false;
	}
}

async function toggle(path) {
	const item = node(path);
	item.open = !item.open;
	if (item.open && item.children === null) {
		await load(path);
	}
}

async function reveal(path) {
	let current = '';
	for (const part of ['', ...segments(path)]) {
		current = part === '' ? '' : joinPath(current, part);
		const item = node(current);
		item.open = true;
		if (item.children === null && !item.loading) {
			await load(current);
		}
	}
}

watch(() => props.current, (path) => reveal(path), { immediate: true });

watch(
	() => [props.version, props.hidden],
	async () => {
		for (const [path, item] of Object.entries(nodes)) {
			if (item.open && item.children !== null && (path === '' || isInside('', path))) {
				await load(path);
			}
		}
	},
);

/** Open folders flattened into rows with their depth */
const rows = computed(() => {
	const out = [];
	const walk = (path, depth) => {
		const item = nodes[path];
		for (const name of item?.children ?? []) {
			const child = joinPath(path, name);
			out.push({ path: child, name, depth, open: Boolean(nodes[child]?.open) });
			if (nodes[child]?.open) {
				walk(child, depth + 1);
			}
		}
	};
	walk('', 1);
	return out;
});

function go(event, path) {
	if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
		return;
	}
	event.preventDefault();
	emit('navigate', path);
}
</script>

<template>
	<nav class="ap-fm-tree" :aria-label="label">
		<a
			:href="pageUrl('')"
			:class="{ 'is-current': current === '' }"
			:aria-current="current === '' ? 'location' : undefined"
			:style="{ paddingInlineStart: '8px' }"
			@click="go($event, '')"
		>
			<ApIcon name="home" />
			<span>{{ home }}</span>
		</a>
		<a
			v-for="row in rows"
			:key="row.path"
			:href="pageUrl(row.path)"
			:class="{ 'is-current': current === row.path }"
			:aria-current="current === row.path ? 'location' : undefined"
			:aria-expanded="row.open"
			:style="{ paddingInlineStart: `${8 + row.depth * 14}px` }"
			@click="go($event, row.path)"
		>
			<span class="chev-btn" role="presentation" @click.stop.prevent="toggle(row.path)">
				<ApIcon :name="row.open ? 'chevron-down' : 'chevron-right'" class="chev" />
			</span>
			<ApIcon name="folder" class="is-dir" />
			<span>{{ row.name }}</span>
		</a>
	</nav>
</template>
