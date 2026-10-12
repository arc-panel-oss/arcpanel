<script setup>
// The php.ini editor of a PHP version (E41 spec §6, §8): CodeMirror in ini
// mode, Validate (v-check-sys-php-ini) with line markers and a list of the
// findings, and Save, which asks before saving a file with warnings.
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { request } from '../../http.js';
import { toast } from '../../toast.js';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { countFindings } from './findings.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const host = ref(null);
const findings = ref([]);
const checked = ref(false);
const busy = ref('');
const error = ref('');
const dirty = ref(false);
let editor = null;
let markers = null;

const counts = computed(() => countFindings(findings.value));

function show(list) {
	findings.value = list;
	checked.value = true;
	if (editor && markers) {
		markers.showMarkers(editor.view, list);
	}
}

async function check() {
	const answer = await request(props.initial.checkUrl, { method: 'POST', body: { content: editor.value() } });
	show(answer.findings ?? []);
	return answer.findings ?? [];
}

async function validate() {
	if (busy.value) {
		return;
	}
	busy.value = 'validate';
	error.value = '';
	try {
		await check();
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e);
	} finally {
		busy.value = '';
	}
}

async function save() {
	if (busy.value || !props.initial.writable) {
		return;
	}
	busy.value = 'save';
	error.value = '';
	try {
		const list = await check();
		const { errors, warnings } = countFindings(list);
		if (errors > 0) {
			error.value = t('The php.ini has errors and was not saved.');
			return;
		}
		if (warnings > 0 && !window.confirm(t('Save with %s warnings?', warnings))) {
			return;
		}
		const content = editor.value();
		const answer = await request(props.initial.saveUrl, { method: 'POST', body: { content } });
		editor.markSaved(content);
		show(answer.findings ?? []);
		toast('ok', answer.message ?? t('Changes have been saved.'));
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e);
	} finally {
		busy.value = '';
	}
}

function jump(line) {
	if (editor && markers && line > 0) {
		markers.goToLine(editor.view, line);
	}
}

function beforeUnload(event) {
	if (dirty.value) {
		event.preventDefault();
		event.returnValue = t('Leave this page? Your changes are not saved.');
	}
}

onMounted(async () => {
	await nextTick();
	const [{ createEditor }, markerModule] = await Promise.all([
		import('../file-manager/editor/codemirror.js'),
		import('./markers.js'),
	]);
	markers = markerModule;
	editor = createEditor(host.value, {
		doc: props.initial.content,
		language: 'ini',
		readOnly: !props.initial.writable,
		wrap: false,
		label: t('Content of %s', props.initial.file),
		onSave: save,
		onUpdate: (state) => {
			dirty.value = state.dirty;
		},
		extensions: markers.markersExtension(),
	});
	window.addEventListener('beforeunload', beforeUnload);
});

onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', beforeUnload);
	editor?.destroy();
});
</script>

<template>
	<div class="ap-stack">
		<p class="ap-service-file-head">
			<span class="mono">{{ initial.file }}</span>
			<span v-if="dirty" class="ap-badge"><span class="dot"></span>{{ t('Unsaved changes') }}</span>
			<span v-if="!initial.writable" class="ap-badge"><span class="dot"></span>{{ t('Read-only') }}</span>
		</p>
		<div ref="host" class="ap-service-file-editor"></div>

		<div v-if="checked" class="ap-ini-findings" role="status">
			<p v-if="findings.length === 0" class="ap-ini-ok"><ApIcon name="check-circle" />{{ t('No problems found.') }}</p>
			<ul v-else class="ap-list" role="list">
				<li v-for="(finding, position) in findings" :key="position" :class="['ap-ini-finding', `is-${finding.level}`]">
					<span :class="['ap-badge', finding.level === 'warning' ? 'is-warn' : 'is-danger']"><span class="dot"></span>{{ finding.level === 'warning' ? t('Warning') : t('Error') }}</span>
					<button v-if="finding.line > 0" type="button" class="ap-btn ap-btn-ghost ap-btn-sm mono" @click="jump(finding.line)">{{ t('Line %s', finding.line) }}</button>
					<span>{{ finding.message }}</span>
				</li>
			</ul>
		</div>

		<p v-if="error" class="ap-error" role="alert">{{ error }}</p>

		<div class="ap-form-actions">
			<button type="button" class="ap-btn" :disabled="Boolean(busy)" @click="validate">
				<ApIcon name="check" />{{ busy === 'validate' ? t('Validating…') : t('Validate') }}
			</button>
			<button v-if="initial.writable" type="button" class="ap-btn ap-btn-primary" :disabled="Boolean(busy) || !dirty" @click="save">
				<ApIcon name="save" />{{ busy === 'save' ? t('Saving…') : t('Save') }}
			</button>
		</div>
	</div>
</template>
