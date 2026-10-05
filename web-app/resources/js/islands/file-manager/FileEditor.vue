<script setup>
// The file editor (E3 spec §7.6): CodeMirror 6 loaded on first use, Ctrl+S,
// a dirty marker, a prompt before leaving with changes, and the "changed on
// disk" dialog when the hash guard refuses a save.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { languageFor } from './editor/indent.js';
import { baseName, formatSize } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	path: { type: String, required: true },
	manage: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'saved']);

const host = ref(null);
const loading = ref(true);
const failed = ref('');
const saving = ref(false);
const dirty = ref(false);
const cursor = ref({ line: 1, column: 1 });
const meta = ref({ size: 0, mime: '', utf8: true, hash: '' });
const wrap = ref(readWrap());
const changedOnDisk = ref(false);
const leaving = ref(false);
let editor = null;

const name = computed(() => baseName(props.path));
const language = computed(() => languageFor(name.value));
const readOnly = computed(() => !props.manage || !meta.value.utf8);
const isHtaccess = computed(() => name.value === '.htaccess');

function readWrap() {
	try {
		return localStorage.getItem('arc.files.wrap') === 'yes';
	} catch {
		return false;
	}
}

function toggleWrap() {
	wrap.value = !wrap.value;
	editor?.setWrap(wrap.value);
	try {
		localStorage.setItem('arc.files.wrap', wrap.value ? 'yes' : 'no');
	} catch {
		// not remembered
	}
}

async function load() {
	loading.value = true;
	failed.value = '';
	try {
		const data = await props.api.read(props.path);
		meta.value = { size: data.size, mime: data.mime, utf8: data.utf8, hash: data.hash };
		const { createEditor } = await import('./editor/codemirror.js');
		editor?.destroy();
		editor = createEditor(host.value, {
			doc: data.content,
			language: language.value,
			readOnly: readOnly.value,
			wrap: wrap.value,
			label: props.t('Content of %s', name.value),
			onSave: save,
			onUpdate: (state) => {
				dirty.value = state.dirty;
				cursor.value = { line: state.line, column: state.column };
			},
		});
		dirty.value = false;
		editor.focus();
	} catch (error) {
		failed.value = error?.code === 'E_LIMIT' ? props.t('This file is too large to edit; download it instead.') : error instanceof Error ? error.message : String(error);
	} finally {
		loading.value = false;
	}
}

async function save(force = false) {
	if (!editor || readOnly.value || saving.value) {
		return;
	}
	saving.value = true;
	const content = editor.value();
	try {
		let hash = meta.value.hash;
		if (force) {
			hash = (await props.api.read(props.path)).hash;
		}
		const result = await props.api.save(props.path, content, hash || null);
		meta.value = { ...meta.value, hash: result.hash, size: result.size };
		editor.markSaved(content);
		dirty.value = false;
		changedOnDisk.value = false;
		props.api.toast('ok', props.t('%s has been saved.', name.value));
		emit('saved', props.path);
	} catch (error) {
		if (error?.status === 409) {
			changedOnDisk.value = true;
		} else {
			props.api.fail(error);
		}
	} finally {
		saving.value = false;
	}
}

async function reload() {
	changedOnDisk.value = false;
	await load();
}

function close() {
	if (dirty.value) {
		leaving.value = true;
		return;
	}
	emit('close');
}

function discard() {
	leaving.value = false;
	dirty.value = false;
	emit('close');
}

function beforeUnload(event) {
	if (dirty.value) {
		event.preventDefault();
		event.returnValue = '';
	}
}

defineExpose({ isDirty: () => dirty.value, close });

onMounted(() => {
	load();
	window.addEventListener('beforeunload', beforeUnload);
});
onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', beforeUnload);
	editor?.destroy();
});
</script>

<template>
	<div class="ap-editor">
		<div class="ap-editor-bar">
			<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="close"><ApIcon name="chevron-left" />{{ t('Back to the folder') }}</button>
			<span class="ap-fm-sep"></span>
			<span class="name">
				<ApIcon name="file-code" />
				<span>{{ name }}</span>
				<span v-if="dirty" class="dirty" role="img" :aria-label="t('Unsaved changes')"></span>
			</span>
			<div class="ap-topbar-spacer"></div>
			<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :disabled="loading || !!failed" @click="editor?.search()"><ApIcon name="search" />{{ t('Find') }}</button>
			<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :aria-pressed="wrap" @click="toggleWrap"><ApIcon name="wrap" />{{ t('Wrap lines') }}</button>
			<button v-if="!readOnly" type="button" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="saving || loading || !dirty" @click="save()">
				<ApIcon name="save" />{{ saving ? t('Saving…') : t('Save') }}<span class="ap-kbd">Ctrl S</span>
			</button>
		</div>
		<div v-if="isHtaccess && !readOnly" class="ap-editor-hint" role="note">
			<ApIcon name="info" />
			<span>{{ t('Apache reads .htaccess on the next request; no restart is needed. NGINX-only domains ignore it.') }}</span>
		</div>
		<div v-if="!meta.utf8 && !loading" class="ap-editor-hint is-warn" role="status">
			<ApIcon name="alert" />
			<span>{{ t('This file is not UTF-8 text. It is shown read-only so nothing is changed by accident.') }}</span>
		</div>
		<div v-if="!manage && !loading" class="ap-editor-hint" role="note">
			<ApIcon name="lock" />
			<span>{{ t('Read-only: changes are disabled.') }}</span>
		</div>
		<div v-if="failed" class="ap-empty">
			<div class="ap-empty-art"><ApIcon name="alert" /></div>
			<h3>{{ failed }}</h3>
			<p><button type="button" class="ap-btn" @click="emit('close')">{{ t('Back to the folder') }}</button></p>
		</div>
		<div v-show="!failed" ref="host" class="ap-code-view" :aria-busy="loading"></div>
		<div class="ap-editor-status">
			<span>{{ t('Line %d, column %d', cursor.line, cursor.column) }}</span>
			<span>{{ language || t('Plain text') }}</span>
			<span>UTF-8</span>
			<span class="sp">{{ formatSize(meta.size) }}</span>
		</div>

		<ApDialog :open="changedOnDisk" :title="t('The file changed on disk')" icon="alert" @close="changedOnDisk = false">
			<p>{{ t('Someone saved %s after you opened it. Reload to see their version, or overwrite it with yours.', name) }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" @click="changedOnDisk = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn" data-autofocus @click="reload">{{ t('Reload') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" :disabled="saving" @click="save(true)">{{ t('Overwrite') }}</button>
			</template>
		</ApDialog>
		<ApDialog :open="leaving" :title="t('Leave without saving?')" icon="alert" @close="leaving = false">
			<p>{{ t('Your changes to %s are not saved.', name) }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="leaving = false">{{ t('Keep editing') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" @click="discard">{{ t('Discard changes') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
