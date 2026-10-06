<script setup>
// Config editor of a service (E7 batch 3 spec §9.2): one tab per file, the
// CodeMirror editor of the file manager, the basic options of nginx, PHP and
// MySQL written into the text, and a save that sends every changed file.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { request } from '../../http.js';
import { toast } from '../../toast.js';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { OPTION_KEYS, readOptions, writeOption } from './options.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const files = props.initial.files;
const kind = props.initial.options;
const active = ref(0);
const restart = ref(true);
const saving = ref(false);
const error = ref('');
const dirty = reactive(files.map(() => false));
const hosts = ref([]);
const editors = [];
const options = reactive(kind ? readOptions(kind, files[0].content) : {});
const optionKeys = computed(() => (kind ? OPTION_KEYS[kind].filter((key) => key in options) : []));
const anyDirty = computed(() => dirty.some(Boolean));
const anyWritable = files.some((file) => file.writable);

function setOption(key, value) {
	options[key] = value;
	const editor = editors[0];
	if (!editor) {
		return;
	}
	const text = editor.value();
	const next = writeOption(kind, text, key, value);
	if (next !== text) {
		editor.view.dispatch({ changes: { from: 0, to: text.length, insert: next } });
	}
}

/** Keep the basic options in step with edits in the text of the first file. */
function syncOptions() {
	if (kind && editors[0]) {
		Object.assign(options, readOptions(kind, editors[0].value()));
	}
}

async function save() {
	if (saving.value || !anyDirty.value) {
		return;
	}
	saving.value = true;
	error.value = '';
	try {
		for (const [position, file] of files.entries()) {
			if (!dirty[position] || !file.writable) {
				continue;
			}
			const content = editors[position].value();
			await request(props.initial.saveUrl, {
				method: 'POST',
				body: { index: file.index, content, restart: props.initial.alwaysRestart || restart.value },
			});
			editors[position].markSaved(content);
		}
		toast('ok', t('Changes have been saved.'));
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e);
	} finally {
		saving.value = false;
	}
}

function beforeUnload(event) {
	if (anyDirty.value) {
		event.preventDefault();
		event.returnValue = t('Leave this page? Your changes are not saved.');
	}
}

onMounted(async () => {
	await nextTick();
	const { createEditor } = await import('../file-manager/editor/codemirror.js');
	for (const [position, file] of files.entries()) {
		editors[position] = createEditor(hosts.value[position], {
			doc: file.content,
			language: props.initial.language,
			readOnly: !file.writable,
			wrap: false,
			label: t('Content of %s', file.path),
			onSave: save,
			onUpdate: (state) => {
				dirty[position] = state.dirty;
				if (position === 0) {
					syncOptions();
				}
			},
		});
	}
	window.addEventListener('beforeunload', beforeUnload);
});

onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', beforeUnload);
	for (const editor of editors) {
		editor?.destroy();
	}
});
</script>

<template>
	<div class="ap-stack">
		<section v-if="optionKeys.length" class="ap-card" aria-labelledby="se-options-title">
			<div class="ap-card-head">
				<div class="ap-card-title"><ApIcon name="sliders" /><h2 id="se-options-title">{{ t('Basic options') }}</h2></div>
			</div>
			<div class="ap-form-grid">
				<div v-for="key in optionKeys" :key="key" class="ap-field">
					<label class="ap-label" :for="`se-opt-${key}`"><span class="mono">{{ key }}</span></label>
					<input
						:id="`se-opt-${key}`"
						class="ap-input ap-input-mono"
						type="text"
						:value="options[key]"
						:disabled="!files[0].writable"
						@change="setOption(key, $event.target.value)"
					/>
				</div>
			</div>
		</section>

		<section class="ap-card ap-card-flush" aria-labelledby="se-files-title">
			<h2 id="se-files-title" class="sr-only">{{ initial.service }}</h2>
			<div v-if="files.length > 1" class="ap-tabs" role="tablist" :aria-label="initial.service">
				<button
					v-for="(file, position) in files"
					:id="`se-tab-${position}`"
					:key="file.path"
					type="button"
					class="ap-tab"
					role="tab"
					:aria-selected="active === position ? 'true' : 'false'"
					:aria-controls="`se-panel-${position}`"
					@click="active = position"
				>
					{{ file.label }}<span v-if="dirty[position]" class="ap-dirty-dot" :title="t('Unsaved changes')"> •</span>
				</button>
			</div>
			<div
				v-for="(file, position) in files"
				v-show="active === position"
				:id="`se-panel-${position}`"
				:key="file.path"
				:role="files.length > 1 ? 'tabpanel' : null"
				:aria-labelledby="files.length > 1 ? `se-tab-${position}` : null"
				class="ap-service-file"
			>
				<p class="ap-service-file-head">
					<span class="mono">{{ file.path }}</span>
					<span v-if="!file.writable" class="ap-badge"><span class="dot"></span>{{ t('Read-only') }}</span>
				</p>
				<div :ref="(el) => (hosts[position] = el)" class="ap-service-file-editor"></div>
			</div>
		</section>

		<p v-if="error" class="ap-error" role="alert">{{ error }}</p>

		<div v-if="anyWritable" class="ap-form-actions">
			<label v-if="!initial.alwaysRestart" class="ap-check">
				<input v-model="restart" type="checkbox" />
				<span>{{ t('Restart the service after saving') }}</span>
			</label>
			<button type="button" class="ap-btn ap-btn-primary" :disabled="saving || !anyDirty" @click="save">
				<ApIcon name="save" />{{ saving ? t('Saving…') : t('Save') }}
			</button>
		</div>
	</div>
</template>
