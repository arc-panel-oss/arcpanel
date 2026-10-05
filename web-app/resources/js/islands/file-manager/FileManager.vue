<script setup>
// File manager of one account (E3 spec §7): folder tree, list or tiles,
// details, operations, uploads, downloads, preview and the editor. The
// folder is kept in the URL (?path=, &edit=), so back, forward and links
// work.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { csrfToken } from '../../http.js';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createApi, createSnapshotApi } from './api.js';
import ConflictDialog from './ConflictDialog.vue';
import ContextMenu from './ContextMenu.vue';
import FileAside from './FileAside.vue';
import FileEditor from './FileEditor.vue';
import FileList from './FileList.vue';
import FileTree from './FileTree.vue';
import FolderPickDialog from './FolderPickDialog.vue';
import {
	baseName,
	breadcrumb,
	formatSize,
	isEditable,
	isExtractable,
	joinPath,
	parentPath,
	previewKind,
	quotaUse,
	segments,
	select,
	shortcut,
	sortEntries,
	zipName,
} from './model.js';
import NameDialog from './NameDialog.vue';
import PermissionsDialog from './PermissionsDialog.vue';
import PreviewDialog from './PreviewDialog.vue';
import UploadPanel from './UploadPanel.vue';
import { createUploader, filesFromDrop, filesFromInput } from './uploader.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
// Snapshot mode: a restic snapshot of the home directory, read-only, with
// "Restore" (E7 batch 1 spec §10.3)
const snapshot = props.initial.snapshot ?? null;
const api = snapshot ? createSnapshotApi(props.initial.base, snapshot.id, t) : createApi(props.initial.base, t);
const manage = Boolean(props.initial.abilities?.manage) && !props.initial.readOnly && !snapshot;
const canRestore = Boolean(snapshot?.restore);
const limits = props.initial.limits;

const path = ref(props.initial.listing?.path ?? props.initial.path ?? '');
const dir = ref(props.initial.listing?.dir ?? null);
const loaded = ref(props.initial.listing?.entries ?? []);
const total = ref(props.initial.listing?.total ?? 0);
const loading = ref(false);
const sort = reactive({ key: 'name', order: 'asc' });
const hidden = ref(readPreference('hidden', 'yes') === 'yes');
const view = ref(readPreference('view', 'list'));
const selected = ref([]);
const anchor = ref(null);
const clipboard = ref(null);
const treeVersion = ref(0);
const editing = ref(snapshot ? null : (props.initial.edit ?? null));
const editor = ref(null);
const list = ref(null);
const quota = ref(props.initial.quota ?? { used: 0, quota: null });

const search = reactive({ query: '', results: null, truncated: false, loading: false });
const menu = reactive({ open: false, x: 0, y: 0, entry: null });
const naming = reactive({ open: false, mode: '', value: '', busy: false, error: '' });
const deleting = reactive({ open: false, entries: [], busy: false });
const perms = reactive({ open: false, entries: [], busy: false });
const picking = reactive({ open: false, mode: 'copy', busy: false });
const conflict = reactive({ open: false, names: [], busy: false, retry: null });
const preview = reactive({ open: false, entry: null, kind: '', url: '' });
const shortcutsOpen = ref(false);
const restoring = reactive({ open: false, entry: null, busy: false });

const uploadTick = ref(0);
const showUploads = ref(false);
const dragging = ref(false);
const fileInput = ref(null);
const folderInput = ref(null);
let refreshTimer = null;
const uploader = createUploader({
	base: props.initial.base,
	csrf: csrfToken,
	maxBytes: limits.uploadMax,
	onChange: () => {
		uploadTick.value++;
	},
	onDone: (item) => {
		if (item.dest === path.value) {
			clearTimeout(refreshTimer);
			refreshTimer = setTimeout(() => refresh(), 400);
		}
	},
});

const entries = computed(() => (total.value <= loaded.value.length ? sortEntries(loaded.value, sort.key, sort.order) : loaded.value));
const selectedEntries = computed(() => entries.value.filter((entry) => selected.value.includes(entry.name)));
const canWriteHere = computed(() => manage && dir.value?.writable !== false);
const cutNames = computed(() => (clipboard.value?.mode === 'cut' && clipboard.value.from === path.value ? clipboard.value.names : []));
const crumbs = computed(() => breadcrumb(path.value));
const disk = computed(() => quotaUse(quota.value));
const selectionSize = computed(() => selectedEntries.value.reduce((sum, entry) => sum + (entry.dir ? 0 : entry.size), 0));
const imageEntries = computed(() => entries.value.filter((entry) => previewKind(entry) === 'image'));

function readPreference(key, fallback) {
	try {
		return localStorage.getItem(`arc.files.${key}`) ?? fallback;
	} catch {
		return fallback;
	}
}

function writePreference(key, value) {
	try {
		localStorage.setItem(`arc.files.${key}`, value);
	} catch {
		// not remembered
	}
}

/** URL of the page for a folder (and a file in the editor) */
function pageUrl(folder, edit = null) {
	const encode = (value) => segments(value).map(encodeURIComponent).join('/');
	let url = props.initial.page;
	const params = [];
	if (snapshot) params.push(`snapshot=${encodeURIComponent(snapshot.id)}`);
	if (folder !== '') params.push(`path=${encode(folder)}`);
	if (edit) params.push(`edit=${encode(edit)}`);
	if (params.length > 0) url += `?${params.join('&')}`;
	return url;
}

function entryUrl(entry) {
	const full = joinPath(path.value, entry.name);
	return entry.dir ? pageUrl(full) : pageUrl(path.value, full);
}

function pushState(replace = false) {
	const url = new URL(pageUrl(path.value, editing.value), location.href).href;
	if (url !== location.href) {
		history[replace ? 'replaceState' : 'pushState']({ path: path.value, edit: editing.value }, '', url);
	}
}

// Only the newest listing counts, and a refresh reads the folder the user
// went to last: an operation that ends after the user moved on to another
// folder must not take them back
let listing = 0;
let target = path.value;

async function load(next, { push = true, select: pick = null } = {}) {
	const request = ++listing;
	target = next;
	loading.value = true;
	try {
		const data = await api.list(next, { sort: sort.key, order: sort.order, hidden: hidden.value });
		if (request !== listing) {
			return;
		}
		path.value = data.path;
		dir.value = data.dir;
		loaded.value = data.entries;
		total.value = data.total;
		selected.value = pick ? [pick].filter((name) => data.entries.some((entry) => entry.name === name)) : [];
		anchor.value = selected.value[0] ?? null;
		search.results = null;
		if (push) {
			pushState();
		}
		// The link that was clicked is gone: keep the keyboard on the list
		nextTick(() => {
			if (!editing.value && (document.activeElement === null || document.activeElement === document.body)) {
				list.value?.focus({ preventScroll: true });
			}
		});
	} catch (error) {
		if (request === listing) {
			api.fail(error);
		}
	} finally {
		if (request === listing) {
			loading.value = false;
		}
	}
}

function refresh(pick = null) {
	treeVersion.value++;
	return load(target, { push: false, select: pick ?? selected.value[0] ?? null });
}

async function more() {
	if (loading.value || loaded.value.length >= total.value) {
		return;
	}
	const request = listing;
	loading.value = true;
	try {
		const data = await api.list(path.value, { offset: loaded.value.length, sort: sort.key, order: sort.order, hidden: hidden.value });
		if (request !== listing) {
			return;
		}
		loaded.value = [...loaded.value, ...data.entries];
		total.value = data.total;
	} catch (error) {
		api.fail(error);
	} finally {
		loading.value = false;
	}
}

function navigate(next) {
	editing.value = null;
	load(next);
}

function setSort(key) {
	if (sort.key === key) {
		sort.order = sort.order === 'asc' ? 'desc' : 'asc';
	} else {
		sort.key = key;
		sort.order = 'asc';
	}
	// Folders with more entries than loaded are sorted by the server
	if (total.value > loaded.value.length) {
		load(path.value, { push: false });
	}
}

function toggleHidden() {
	hidden.value = !hidden.value;
	writePreference('hidden', hidden.value ? 'yes' : 'no');
	refresh();
}

function setView(next) {
	view.value = next;
	writePreference('view', next);
}

function onSelect(name, keys) {
	const result = select(
		entries.value.map((entry) => entry.name),
		new Set(selected.value),
		anchor.value,
		name,
		keys,
	);
	selected.value = [...result.selected];
	anchor.value = result.anchor;
}

function toggleOne(name) {
	selected.value = selected.value.includes(name) ? selected.value.filter((item) => item !== name) : [...selected.value, name];
	anchor.value = name;
}

function toggleAll() {
	selected.value = selected.value.length === entries.value.length ? [] : entries.value.map((entry) => entry.name);
}

function full(entry) {
	return joinPath(path.value, entry.name);
}

/* ---------- Opening ---------- */

function open(entry) {
	if (entry.type === 'l' && !entry.linkInside) {
		api.toast('warn', t('This link points outside the home directory and cannot be opened.'));
		return;
	}
	if (!entry.validName) {
		api.toast('warn', t('This name is not valid UTF-8; rename it over SSH or FTP.'));
		return;
	}
	if (entry.dir) {
		navigate(full(entry));
	} else if (snapshot) {
		// Files of a snapshot are only selected, for Restore
		selected.value = [entry.name];
		anchor.value = entry.name;
	} else if (isEditable(entry, limits.editMax)) {
		edit(entry);
	} else if (previewKind(entry)) {
		showPreview(entry);
	} else {
		download([entry]);
	}
}

function edit(entry) {
	editing.value = full(entry);
	pushState();
}

function closeEditor() {
	editing.value = null;
	pushState();
	nextTick(() => list.value?.focus());
}

async function showPreview(entry) {
	Object.assign(preview, { open: true, entry, kind: previewKind(entry), url: '' });
	try {
		preview.url = (await api.download([full(entry)])).url;
	} catch (error) {
		preview.open = false;
		api.fail(error);
	}
}

function stepPreview(step) {
	const images = imageEntries.value;
	const index = images.findIndex((entry) => entry.name === preview.entry?.name);
	const next = images[index + step];
	if (next) {
		selected.value = [next.name];
		showPreview(next);
	}
}

async function download(items = selectedEntries.value) {
	if (items.length === 0) {
		return;
	}
	try {
		const ticket = await api.download(items.map(full));
		const link = document.createElement('a');
		link.href = ticket.url;
		link.download = ticket.name;
		document.body.append(link);
		link.click();
		link.remove();
	} catch (error) {
		api.fail(error);
	}
}

/* ---------- Snapshot mode ---------- */

function askRestore(entry = selectedEntries.value[0]) {
	if (canRestore && entry) {
		Object.assign(restoring, { open: true, entry, busy: false });
	}
}

async function confirmRestore() {
	restoring.busy = true;
	try {
		const data = await api.restore(full(restoring.entry));
		restoring.open = false;
		api.toast('ok', data.message);
	} catch (error) {
		api.fail(error);
	} finally {
		restoring.busy = false;
	}
}

/* ---------- Operations ---------- */

/** Run an operation that may answer 409 with names; ask and run it again. */
async function withConflicts(action, onDone) {
	try {
		await action(null);
		onDone?.();
	} catch (error) {
		if (error?.status === 409 && error.code === 'E_EXISTS') {
			Object.assign(conflict, {
				open: true,
				names: error.conflicts ?? [],
				busy: false,
				retry: async (choice) => {
					conflict.busy = true;
					try {
						await action(choice);
						conflict.open = false;
						onDone?.();
					} catch (inner) {
						conflict.open = false;
						api.fail(inner);
					} finally {
						conflict.busy = false;
					}
				},
			});
		} else {
			api.fail(error);
		}
	}
}

function askName(mode) {
	const entry = selectedEntries.value[0];
	const values = {
		mkdir: '',
		touch: '',
		rename: entry?.name ?? '',
		compress: zipName(selectedEntries.value.map((item) => item.name), path.value),
	};
	Object.assign(naming, { open: true, mode, value: values[mode], busy: false, error: '' });
}

const namingText = computed(
	() =>
		({
			mkdir: { title: t('New folder'), label: t('Folder name'), submit: t('Create'), icon: 'folder' },
			touch: { title: t('New file'), label: t('File name'), submit: t('Create'), icon: 'file' },
			rename: { title: t('Rename'), label: t('New name'), submit: t('Rename'), icon: 'edit' },
			compress: { title: t('Compress to zip'), label: t('Archive name'), submit: t('Compress'), icon: 'archive' },
		})[naming.mode] ?? { title: '', label: '', submit: '', icon: 'edit' },
);

function nameCheck(name) {
	if (naming.mode === 'compress' && !/\.zip$/i.test(name)) {
		return 'The archive name ends with .zip.';
	}
	return '';
}

async function submitName(name) {
	naming.busy = true;
	naming.error = '';
	try {
		if (naming.mode === 'mkdir') {
			await api.mkdir(path.value, name);
		} else if (naming.mode === 'touch') {
			await api.touch(path.value, name);
		} else if (naming.mode === 'rename') {
			await api.rename(full(selectedEntries.value[0]), name);
		} else if (naming.mode === 'compress') {
			await api.compress(selectedEntries.value.map(full), name);
		}
		naming.open = false;
		await refresh(name);
	} catch (error) {
		if (error?.status === 409) {
			naming.error = t('%s already exists here.', name);
		} else if (error?.status === 422) {
			naming.error = Object.values(error.errors ?? {})[0]?.[0] ?? error.message;
		} else {
			naming.open = false;
			api.fail(error);
		}
	} finally {
		naming.busy = false;
	}
}

function askDelete(items = selectedEntries.value) {
	if (items.length > 0) {
		Object.assign(deleting, { open: true, entries: items, busy: false });
	}
}

async function confirmDelete() {
	deleting.busy = true;
	try {
		await api.remove(deleting.entries.map(full));
		api.toast('ok', deleting.entries.length === 1 ? t('%s has been deleted.', deleting.entries[0].name) : t('%d items have been deleted.', deleting.entries.length));
		deleting.open = false;
		await refresh();
	} catch (error) {
		deleting.open = false;
		api.fail(error);
		await refresh();
	} finally {
		deleting.busy = false;
	}
}

function toClipboard(mode) {
	if (selectedEntries.value.length > 0) {
		clipboard.value = { mode, from: path.value, names: selectedEntries.value.map((entry) => entry.name) };
		api.toast('info', mode === 'cut' ? t('%d items cut; paste them in another folder.', clipboard.value.names.length) : t('%d items copied.', clipboard.value.names.length));
	}
}

function paste() {
	const clip = clipboard.value;
	if (!clip || !canWriteHere.value) {
		return;
	}
	const paths = clip.names.map((name) => joinPath(clip.from, name));
	const move = clip.mode === 'cut';
	withConflicts(
		(choice) => (move ? api.move(paths, path.value, choice) : api.copy(paths, path.value, choice)),
		() => {
			if (move) {
				clipboard.value = null;
			}
			refresh();
		},
	);
}

function transfer(dest) {
	const paths = selectedEntries.value.map(full);
	const move = picking.mode === 'move';
	picking.open = false;
	withConflicts(
		(choice) => (move ? api.move(paths, dest, choice) : api.copy(paths, dest, choice)),
		() => {
			api.toast('ok', move ? t('Moved to ~/%s', dest) : t('Copied to ~/%s', dest));
			refresh();
		},
	);
}

async function extract(entry, intoFolder) {
	const archive = full(entry);
	const folder = entry.name.replace(/\.(tar\.gz|tar\.bz2?|tgz|tbz2?|zip|tar|gz|bz2|7z|rar)$/i, '');
	const dest = intoFolder ? joinPath(path.value, folder) : path.value;
	loading.value = true;
	try {
		await api.extract(archive, dest);
		api.toast('ok', t('%s has been extracted.', entry.name));
		await refresh(intoFolder ? folder : null);
	} catch (error) {
		api.fail(error);
	} finally {
		loading.value = false;
	}
}

async function applyPermissions({ mode, recursive, filesMode }) {
	perms.busy = true;
	try {
		await api.chmod(perms.entries.map(full), mode, recursive, filesMode);
		perms.open = false;
		await refresh();
	} catch (error) {
		perms.open = false;
		api.fail(error);
		await refresh();
	} finally {
		perms.busy = false;
	}
}

/* ---------- Search ---------- */

async function runSearch() {
	const query = search.query.trim();
	if (query === '') {
		search.results = null;
		return;
	}
	search.loading = true;
	try {
		const data = await api.search(path.value, query);
		search.results = data.results;
		search.truncated = data.truncated;
	} catch (error) {
		search.results = null;
		if (error?.code === 'E_COMMAND' || error?.status === 500) {
			api.toast('warn', t('The search took too long; narrow your search.'));
		} else {
			api.fail(error);
		}
	} finally {
		search.loading = false;
	}
}

function openResult(result) {
	if (result.type === 'd') {
		navigate(result.path);
	} else {
		load(parentPath(result.path), { select: baseName(result.path) });
	}
}

/* ---------- Menu and keyboard ---------- */

function menuItems(entry) {
	if (!entry) {
		return [
			...(canWriteHere.value
				? [
						{ action: 'mkdir', label: t('New folder'), icon: 'folder' },
						{ action: 'touch', label: t('New file'), icon: 'file' },
						{ action: 'upload', label: t('Upload files'), icon: 'upload' },
						{ action: 'upload-folder', label: t('Upload a folder'), icon: 'upload' },
					]
				: []),
			...(canWriteHere.value && clipboard.value ? [{ action: 'paste', label: t('Paste'), icon: 'clipboard', kbd: 'Ctrl V' }] : []),
			{ separator: true },
			{ action: 'select-all', label: t('Select all'), icon: 'check', kbd: 'Ctrl A' },
			{ action: 'refresh', label: t('Refresh'), icon: 'refresh' },
		];
	}
	const single = selectedEntries.value.length <= 1;
	if (snapshot) {
		return [
			...(single && entry.dir ? [{ action: 'open', label: t('Open'), icon: 'folder', kbd: 'Enter' }] : []),
			...(single && canRestore ? [{ action: 'restore', label: t('Restore'), icon: 'history' }] : []),
		];
	}
	const items = [];
	if (single) {
		items.push({ action: 'open', label: entry.dir ? t('Open') : isEditable(entry, limits.editMax) ? (manage ? t('Edit') : t('View')) : previewKind(entry) ? t('Preview') : t('Download'), icon: entry.dir ? 'folder' : 'edit', kbd: 'Enter' });
	}
	items.push({ action: 'download', label: single && !entry.dir ? t('Download') : t('Download as zip'), icon: 'download' });
	if (manage) {
		items.push({ separator: true });
		if (single) items.push({ action: 'rename', label: t('Rename'), icon: 'edit', kbd: 'F2' });
		items.push(
			{ action: 'copy', label: t('Copy'), icon: 'copy', kbd: 'Ctrl C' },
			{ action: 'cut', label: t('Cut'), icon: 'scissors', kbd: 'Ctrl X' },
			{ action: 'copy-to', label: t('Copy to…'), icon: 'copy' },
			{ action: 'move-to', label: t('Move to…'), icon: 'arrow' },
			{ action: 'compress', label: t('Compress to zip'), icon: 'archive' },
		);
		if (single && !entry.dir && isExtractable(entry.name)) {
			items.push({ action: 'extract-here', label: t('Extract here'), icon: 'archive' }, { action: 'extract-folder', label: t('Extract to a new folder'), icon: 'folder' });
		}
		items.push({ action: 'permissions', label: t('Permissions'), icon: 'lock' }, { separator: true }, { action: 'delete', label: t('Delete'), icon: 'trash', danger: true, kbd: 'Del' });
	}
	return items;
}

function openMenu(event, entry = null) {
	if (entry && !selected.value.includes(entry.name)) {
		selected.value = [entry.name];
		anchor.value = entry.name;
	}
	const rect = event.currentTarget instanceof HTMLElement && event.type === 'click' ? event.currentTarget.getBoundingClientRect() : null;
	Object.assign(menu, { open: true, x: rect ? rect.left : event.clientX, y: rect ? rect.bottom + 4 : event.clientY, entry });
}

function closeMenu() {
	menu.open = false;
	menu.entry = null;
}

function act(action) {
	const entry = menu.entry ?? selectedEntries.value[0] ?? null;
	switch (action) {
		case 'open':
			if (entry) open(entry);
			break;
		case 'edit':
			if (entry) edit(entry);
			break;
		case 'preview':
			if (entry) showPreview(entry);
			break;
		case 'download':
			download();
			break;
		case 'restore':
			if (entry) askRestore(entry);
			break;
		case 'mkdir':
		case 'touch':
		case 'rename':
		case 'compress':
			askName(action);
			break;
		case 'copy':
			toClipboard('copy');
			break;
		case 'cut':
			toClipboard('cut');
			break;
		case 'paste':
			paste();
			break;
		case 'copy-to':
		case 'move-to':
			Object.assign(picking, { open: true, mode: action === 'copy-to' ? 'copy' : 'move', busy: false });
			break;
		case 'extract':
		case 'extract-here':
			if (entry) extract(entry, false);
			break;
		case 'extract-folder':
			if (entry) extract(entry, true);
			break;
		case 'permissions':
			Object.assign(perms, { open: true, entries: selectedEntries.value, busy: false });
			break;
		case 'delete':
			askDelete();
			break;
		case 'upload':
			fileInput.value?.click();
			break;
		case 'upload-folder':
			folderInput.value?.click();
			break;
		case 'select-all':
			selected.value = entries.value.map((item) => item.name);
			break;
		case 'refresh':
			refresh();
			break;
	}
}

function onKeydown(event) {
	if (event.target instanceof HTMLElement && event.target.closest('input, textarea, select, [contenteditable="true"]')) {
		return;
	}
	if (event.key === '?' && !event.ctrlKey && !event.metaKey) {
		shortcutsOpen.value = true;
		return;
	}
	const action = shortcut(event);
	if (!action) {
		return;
	}
	const names = entries.value.map((entry) => entry.name);
	const current = selectedEntries.value[0];
	switch (action) {
		case 'open':
			if (current && selectedEntries.value.length === 1) open(current);
			break;
		case 'up':
			if (path.value !== '') navigate(parentPath(path.value));
			break;
		case 'rename':
			if (manage && selectedEntries.value.length === 1) askName('rename');
			break;
		case 'delete':
			if (manage) askDelete();
			break;
		case 'search':
			document.getElementById('fm-search')?.focus();
			break;
		case 'clear':
			selected.value = [];
			break;
		case 'selectAll':
			selected.value = names;
			break;
		case 'copy':
		case 'cut':
			if (manage) toClipboard(action);
			break;
		case 'paste':
			paste();
			break;
		case 'next':
		case 'previous':
		case 'first':
		case 'last': {
			const index = current ? names.indexOf(current.name) : -1;
			const target = { next: Math.min(names.length - 1, index + 1), previous: Math.max(0, index - 1), first: 0, last: names.length - 1 }[action];
			if (names[target] !== undefined) {
				onSelect(names[target], { shift: event.shiftKey });
			}
			break;
		}
		default:
			return;
	}
	event.preventDefault();
}

/* ---------- Uploads ---------- */

function queue(files) {
	if (!canWriteHere.value || files.length === 0) {
		return;
	}
	uploader.add(files, path.value);
	showUploads.value = true;
}

function onFileInput(event) {
	queue(filesFromInput(event.target.files ?? []));
	event.target.value = '';
}

let dragDepth = 0;
function onDragEnter(event) {
	if (canWriteHere.value && [...(event.dataTransfer?.types ?? [])].includes('Files')) {
		dragDepth++;
		dragging.value = true;
	}
}
function onDragLeave() {
	dragDepth = Math.max(0, dragDepth - 1);
	if (dragDepth === 0) {
		dragging.value = false;
	}
}
async function onDrop(event) {
	dragDepth = 0;
	dragging.value = false;
	if (!canWriteHere.value || !event.dataTransfer) {
		return;
	}
	queue(await filesFromDrop(event.dataTransfer));
}

/* ---------- History ---------- */

function onPopState(event) {
	const state = event.state ?? {};
	const params = new URLSearchParams(location.search);
	const next = state.path ?? params.get('path') ?? '';
	editing.value = state.edit ?? params.get('edit') ?? null;
	if (next !== path.value) {
		load(next, { push: false });
	}
}

onMounted(() => {
	history.replaceState({ path: path.value, edit: editing.value }, '', location.href);
	window.addEventListener('popstate', onPopState);
});
onBeforeUnmount(() => {
	window.removeEventListener('popstate', onPopState);
	clearTimeout(refreshTimer);
});
</script>

<template>
	<div class="ap-stack">
		<div v-if="snapshot" class="ap-alert is-info" role="note">
			<ApIcon name="history" />
			<div class="ap-alert-body">
				<p>{{ t('Snapshot %s · %s · read-only', snapshot.id, snapshot.time) }}</p>
				<p><a :href="snapshot.back">{{ t('Back to the backups') }}</a></p>
			</div>
		</div>
		<div v-else-if="initial.readOnly" class="ap-alert is-info" role="note">
			<ApIcon name="info" />
			<div class="ap-alert-body"><p>{{ t('Read-only: changes are disabled.') }}</p></div>
		</div>

		<UploadPanel v-if="showUploads" :uploader="uploader" :tick="uploadTick" :max-bytes="limits.uploadMax" :t="t" @close="showUploads = false" />

		<section class="ap-card ap-card-flush" :aria-label="t('Files')">
			<FileEditor v-if="editing" ref="editor" :key="editing" :api="api" :path="editing" :manage="manage" :t="t" @close="closeEditor" @saved="refresh()" />

			<template v-else>
				<div class="ap-toolbar">
					<nav class="ap-path" :aria-label="t('Current folder')">
						<a :href="pageUrl('')" :aria-label="t('Home folder of %s', initial.user)" @click.prevent="navigate('')"><ApIcon name="home" size="sm" /></a>
						<template v-for="(crumb, index) in crumbs" :key="crumb.path">
							<span>/</span>
							<span v-if="index === crumbs.length - 1" aria-current="page">{{ crumb.name }}</span>
							<a v-else :href="pageUrl(crumb.path)" @click.prevent="navigate(crumb.path)">{{ crumb.name }}</a>
						</template>
					</nav>
					<div class="ap-topbar-spacer"></div>
					<form v-if="!snapshot" class="ap-input-affix ap-toolbar-search" role="search" @submit.prevent="runSearch">
						<ApIcon name="search" size="sm" />
						<input id="fm-search" v-model="search.query" class="ap-input" type="search" :placeholder="t('Search in this folder')" :aria-label="t('Search in this folder')" @keydown.esc="search.results = null" />
					</form>
					<div class="ap-segment" role="group" :aria-label="t('View')">
						<button type="button" :aria-pressed="view === 'list'" @click="setView('list')"><ApIcon name="list" size="sm" /> {{ t('List') }}</button>
						<button type="button" :aria-pressed="view === 'grid'" @click="setView('grid')"><ApIcon name="grid" size="sm" /> {{ t('Grid') }}</button>
					</div>
				</div>

				<div class="ap-fm-actions">
					<template v-if="canWriteHere">
						<button type="button" class="ap-btn ap-btn-primary ap-btn-sm" @click="act('upload')"><ApIcon name="upload" />{{ t('Upload') }}</button>
						<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="act('upload-folder')"><ApIcon name="upload" />{{ t('Upload a folder') }}</button>
						<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="askName('mkdir')"><ApIcon name="folder" />{{ t('New folder') }}</button>
						<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="askName('touch')"><ApIcon name="file" />{{ t('New file') }}</button>
						<span class="ap-fm-sep"></span>
					</template>
					<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Up one folder')" :disabled="path === ''" @click="navigate(parentPath(path))"><ApIcon name="arrow-up" /></button>
					<template v-if="manage">
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Cut')" :disabled="selected.length === 0" @click="toClipboard('cut')"><ApIcon name="scissors" /></button>
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Copy')" :disabled="selected.length === 0" @click="toClipboard('copy')"><ApIcon name="copy" /></button>
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Paste')" :disabled="!clipboard || !canWriteHere" @click="paste"><ApIcon name="clipboard" /></button>
					</template>
					<button v-if="!snapshot" type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Download')" :disabled="selected.length === 0" @click="download()"><ApIcon name="download" /></button>
					<button v-if="canRestore" type="button" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="selected.length !== 1" @click="askRestore()"><ApIcon name="history" />{{ t('Restore') }}</button>
					<template v-if="manage">
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Compress to zip')" :disabled="selected.length === 0 || !canWriteHere" @click="askName('compress')"><ApIcon name="archive" /></button>
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Rename')" :disabled="selected.length !== 1" @click="askName('rename')"><ApIcon name="edit" /></button>
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Permissions')" :disabled="selected.length === 0" @click="act('permissions')"><ApIcon name="lock" /></button>
						<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Delete')" :disabled="selected.length === 0" @click="askDelete()"><ApIcon name="trash" /></button>
					</template>
					<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Refresh')" @click="refresh()"><ApIcon name="refresh" /></button>
					<div class="ap-topbar-spacer"></div>
					<label class="ap-check"><input type="checkbox" :checked="hidden" @change="toggleHidden" /><span>{{ t('Hidden files') }}</span></label>
				</div>

				<div class="ap-fm">
					<FileTree :api="api" :current="path" :hidden="hidden" :home="initial.user" :page-url="(p) => pageUrl(p)" :version="treeVersion" :label="t('Folders')" @navigate="navigate" />

					<div
						ref="list"
						class="ap-fm-main"
						tabindex="0"
						:aria-busy="loading"
						:aria-label="t('Files')"
						@keydown="onKeydown"
						@contextmenu.self.prevent="openMenu($event)"
						@dragenter.prevent="onDragEnter"
						@dragover.prevent
						@dragleave="onDragLeave"
						@drop.prevent="onDrop"
					>
						<div v-if="dragging" class="ap-dropzone" aria-hidden="true">
							<ApIcon name="upload" />
							<b>{{ t('Drop to upload') }}</b>
							<span>{{ t('Files and folders go into ~/%s', path) }}</span>
						</div>

						<template v-if="search.results !== null">
							<div class="ap-fm-actions">
								<b>{{ t('%d results for "%s"', search.results.length, search.query) }}</b>
								<span v-if="search.truncated" class="muted">{{ t('Only the first results are shown; narrow your search.') }}</span>
								<div class="ap-topbar-spacer"></div>
								<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="search.results = null"><ApIcon name="x" />{{ t('Close search') }}</button>
							</div>
							<div class="ap-table-wrap">
								<table class="ap-table ap-table-cards">
									<caption class="sr-only">{{ t('Search results') }}</caption>
									<thead>
										<tr>
											<th scope="col">{{ t('Path') }}</th>
											<th scope="col" class="col-num">{{ t('Size') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="result in search.results" :key="result.path">
											<td :data-label="t('Path')">
												<a href="#" :class="['ap-file', { 'is-dir': result.type === 'd' }]" @click.prevent="openResult(result)">
													<ApIcon :name="result.type === 'd' ? 'folder' : 'file'" />
													<span class="mono">{{ result.path }}</span>
												</a>
											</td>
											<td :data-label="t('Size')" class="col-num mono">{{ result.type === 'd' ? '—' : formatSize(result.size) }}</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div v-if="search.results.length === 0" class="ap-empty"><h3>{{ t('Nothing found.') }}</h3></div>
						</template>

						<template v-else>
							<div v-if="!loading && entries.length === 0" class="ap-empty" @contextmenu.prevent="openMenu($event)">
								<div class="ap-empty-art"><ApIcon name="folder" /></div>
								<h3>{{ t('This folder is empty.') }}</h3>
								<p v-if="canWriteHere">{{ t('Drop files here or use Upload.') }}</p>
							</div>
							<FileList
								v-else
								:entries="entries"
								:view="view"
								:selected="selected"
								:cut="cutNames"
								:sort="sort"
								:total="total"
								:t="t"
								:entry-url="entryUrl"
								:label="t('Contents of ~/%s', path)"
								@open="open"
								@select="onSelect"
								@toggle="toggleOne"
								@toggle-all="toggleAll"
								@menu="openMenu"
								@sort="setSort"
								@more="more"
							/>
						</template>
					</div>

					<FileAside v-if="!snapshot" :entries="selectedEntries" :path="path" :manage="manage" :edit-max="limits.editMax" :api="api" :t="t" @action="act" />
				</div>

				<div class="ap-fm-status">
					<span>{{ t('%d items', total) }}<template v-if="selected.length > 0"> · {{ t('%d selected', selected.length) }} ({{ formatSize(selectionSize) }})</template></span>
					<span v-if="!canWriteHere && manage" class="muted">{{ t('You cannot change this folder.') }}</span>
					<div class="ap-topbar-spacer"></div>
					<span v-if="snapshot"></span>
					<span v-else-if="disk.quota !== null">
						{{ t('Disk %s of %s', formatSize(disk.used * 1048576), formatSize(disk.quota * 1048576)) }}
						<span class="ap-bar" role="meter" :aria-label="t('Disk used')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="disk.percent"><span :style="{ width: `${disk.percent}%` }"></span></span>
					</span>
					<span v-else>{{ t('Disk %s used', formatSize(disk.used * 1048576)) }}</span>
					<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="shortcutsOpen = true"><span class="ap-kbd">?</span>{{ t('Shortcuts') }}</button>
				</div>
			</template>
		</section>

		<input ref="fileInput" type="file" multiple hidden @change="onFileInput" />
		<input ref="folderInput" type="file" webkitdirectory multiple hidden @change="onFileInput" />

		<ContextMenu v-if="menu.open" :x="menu.x" :y="menu.y" :items="menuItems(menu.entry)" :label="t('Actions')" @pick="act" @close="closeMenu" />

		<NameDialog
			:open="naming.open"
			:title="namingText.title"
			:label="namingText.label"
			:submit="namingText.submit"
			:icon="namingText.icon"
			:value="naming.value"
			:busy="naming.busy"
			:error="naming.error"
			:check="nameCheck"
			:t="t"
			@close="naming.open = false"
			@submit="submitName"
		/>

		<ApDialog :open="deleting.open" :title="t('Delete')" icon="trash" @close="deleting.open = false">
			<p>{{ deleting.entries.length === 1 ? t('Delete %s? This cannot be undone.', deleting.entries[0]?.name) : t('Delete %d items? This cannot be undone.', deleting.entries.length) }}</p>
			<ul v-if="deleting.entries.length > 1" class="mono">
				<li v-for="entry in deleting.entries.slice(0, 10)" :key="entry.name">{{ entry.name }}{{ entry.dir ? '/' : '' }}</li>
				<li v-if="deleting.entries.length > 10">{{ t('and %d more', deleting.entries.length - 10) }}</li>
			</ul>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="deleting.open = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" :disabled="deleting.busy" @click="confirmDelete">{{ t('Delete') }}</button>
			</template>
		</ApDialog>

		<ApDialog :open="restoring.open" :title="t('Restore')" icon="history" @close="restoring.open = false">
			<p>{{ t('Restore %s from the snapshot? The current file is overwritten.', restoring.entry?.name ?? '') }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="restoring.open = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-primary" :disabled="restoring.busy" @click="confirmRestore">{{ t('Restore') }}</button>
			</template>
		</ApDialog>

		<PermissionsDialog :open="perms.open" :entries="perms.entries" :busy="perms.busy" :t="t" @close="perms.open = false" @submit="applyPermissions" />

		<FolderPickDialog
			:open="picking.open"
			:title="picking.mode === 'move' ? t('Move to') : t('Copy to')"
			:submit="picking.mode === 'move' ? t('Move here') : t('Copy here')"
			:start="path"
			:home="initial.user"
			:api="api"
			:busy="picking.busy"
			:t="t"
			@close="picking.open = false"
			@submit="transfer"
		/>

		<ConflictDialog :open="conflict.open" :names="conflict.names" :busy="conflict.busy" :t="t" @close="conflict.open = false" @resolve="(choice) => conflict.retry?.(choice)" />

		<PreviewDialog
			:open="preview.open"
			:entry="preview.entry"
			:kind="preview.kind"
			:url="preview.url"
			:has-previous="imageEntries.findIndex((e) => e.name === preview.entry?.name) > 0"
			:has-next="imageEntries.findIndex((e) => e.name === preview.entry?.name) < imageEntries.length - 1"
			:t="t"
			@close="preview.open = false"
			@previous="stepPreview(-1)"
			@next="stepPreview(1)"
			@download="download([preview.entry])"
		/>

		<ApDialog :open="shortcutsOpen" :title="t('Keyboard shortcuts')" icon="info" @close="shortcutsOpen = false">
			<dl class="ap-dl">
				<dt><span class="ap-kbd">Enter</span></dt><dd>{{ t('Open') }}</dd>
				<dt><span class="ap-kbd">Backspace</span></dt><dd>{{ t('Up one folder') }}</dd>
				<dt><span class="ap-kbd">F2</span></dt><dd>{{ t('Rename') }}</dd>
				<dt><span class="ap-kbd">Del</span></dt><dd>{{ t('Delete') }}</dd>
				<dt><span class="ap-kbd">Ctrl C</span> <span class="ap-kbd">Ctrl X</span> <span class="ap-kbd">Ctrl V</span></dt><dd>{{ t('Copy, cut, paste') }}</dd>
				<dt><span class="ap-kbd">Ctrl A</span></dt><dd>{{ t('Select all') }}</dd>
				<dt><span class="ap-kbd">↑</span> <span class="ap-kbd">↓</span></dt><dd>{{ t('Move the selection') }}</dd>
				<dt><span class="ap-kbd">/</span></dt><dd>{{ t('Search in this folder') }}</dd>
				<dt><span class="ap-kbd">Ctrl S</span></dt><dd>{{ t('Save in the editor') }}</dd>
			</dl>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-primary" data-autofocus @click="shortcutsOpen = false">{{ t('Close') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
