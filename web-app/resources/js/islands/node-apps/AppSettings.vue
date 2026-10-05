<script setup>
// Settings of an app: start command and folder, runtime, instances and
// memory (E14 spec §5.5); each part is saved on its own and restarts a
// running app.
import { computed, reactive, ref } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { fieldErrors } from './api.js';
import { composeStart, isRelativePath, isStart, parseStart } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	app: { type: Object, required: true },
	runtimes: { type: Array, default: () => [] },
	defaultRuntime: { type: String, default: '' },
	maxInstances: { type: Number, default: 1 },
	memoryLimit: { type: String, default: '' },
	canManage: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['update']);

const t = props.t;
const parsed = parseStart(props.app.start);
const form = reactive({
	cwd: props.app.cwd,
	kind: parsed.kind,
	value: parsed.value,
	version: props.app.nodeVersion,
	instances: props.app.instances,
	memory: props.app.maxMemory,
});
const busy = ref('');
const errors = ref({});

const start = computed(() => composeStart({ kind: form.kind, value: form.value }));
const memoryMax = computed(() => (props.memoryLimit !== '' ? Number(props.memoryLimit) : 8192));
const path = (part) => `/${encodeURIComponent(props.app.domain)}/${part}`;

async function save(part, body) {
	busy.value = part;
	errors.value = {};
	try {
		const data = await props.api.call('PUT', path(part), body);
		emit('update', data);
		props.api.saved();
	} catch (error) {
		errors.value = fieldErrors(error);
		props.api.fail(error);
	} finally {
		busy.value = '';
	}
}
</script>

<template>
	<div class="ap-stack">
		<form class="ap-stack" novalidate @submit.prevent="save('start', { start, cwd: form.cwd })">
			<h3>{{ t('Start command') }}</h3>
			<div class="ap-form-grid">
				<div class="ap-field" :class="{ 'is-invalid': !isRelativePath(form.cwd) || errors.cwd }">
					<label class="ap-label" for="set-cwd">{{ t('Working directory') }}</label>
					<input id="set-cwd" v-model="form.cwd" class="ap-input ap-input-mono" type="text" spellcheck="false" :disabled="!canManage" />
					<span v-if="errors.cwd" class="ap-error">{{ errors.cwd }}</span>
				</div>
				<div class="ap-field" :class="{ 'is-invalid': !isStart(start) || errors.start }">
					<label class="ap-label" for="set-start">{{ form.kind === 'script' ? t('Script') : t('Start file') }}</label>
					<input id="set-start" v-model="form.value" class="ap-input ap-input-mono" type="text" spellcheck="false" :disabled="!canManage" />
					<span v-if="errors.start" class="ap-error">{{ errors.start }}</span>
				</div>
			</div>
			<div class="ap-row ap-radio-row" role="radiogroup" :aria-label="t('Start with')">
				<label class="ap-check"><input v-model="form.kind" type="radio" value="file" :disabled="!canManage" />{{ t('A file') }}</label>
				<label class="ap-check"><input v-model="form.kind" type="radio" value="script" :disabled="!canManage || app.instances > 1" />{{ t('A script of package.json') }}</label>
			</div>
			<div v-if="canManage" class="ap-actions">
				<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy !== '' || !isRelativePath(form.cwd) || !isStart(start)">
					<span v-if="busy === 'start'" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="save" />{{ t('Save') }}
				</button>
			</div>
		</form>

		<form class="ap-stack" novalidate @submit.prevent="save('version', { version: form.version })">
			<h3>{{ t('Runtime') }}</h3>
			<div class="ap-field">
				<label class="ap-label" for="set-version">{{ t('Node.js version') }}</label>
				<div class="ap-select-wrap">
					<select id="set-version" v-model="form.version" class="ap-select" :disabled="!canManage">
						<option value="default">{{ t('Default (%s)', defaultRuntime ? `v${defaultRuntime}` : '—') }}</option>
						<option v-for="runtime in runtimes" :key="runtime.version" :value="runtime.version">v{{ runtime.version }}</option>
					</select>
					<ApIcon name="chevron-down" size="sm" />
				</div>
			</div>
			<div v-if="canManage" class="ap-actions">
				<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy !== '' || form.version === app.nodeVersion">
					<span v-if="busy === 'version'" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="save" />{{ t('Save') }}
				</button>
			</div>
		</form>

		<form class="ap-stack" novalidate @submit.prevent="save('scale', { instances: Number(form.instances), memory: Number(form.memory) })">
			<h3>{{ t('Resources') }}</h3>
			<div class="ap-form-grid">
				<div class="ap-field" :class="{ 'is-invalid': errors.instances }">
					<label class="ap-label" for="set-instances">{{ t('Instances') }}</label>
					<input id="set-instances" v-model.number="form.instances" class="ap-input ap-input-mono" type="number" min="1" :max="maxInstances" :disabled="!canManage" />
					<span class="ap-hint">{{ t('More than one runs the app in cluster mode (at most %d).', maxInstances) }}</span>
					<span v-if="errors.instances" class="ap-error">{{ errors.instances }}</span>
				</div>
				<div class="ap-field" :class="{ 'is-invalid': errors.memory }">
					<label class="ap-label" for="set-memory">{{ t('Restart above (MB)') }}</label>
					<input id="set-memory" v-model.number="form.memory" class="ap-input ap-input-mono" type="number" min="64" :max="memoryMax" :disabled="!canManage" />
					<span class="ap-hint">{{ t('pm2 restarts the app when it uses more memory (at most %d MB).', memoryMax) }}</span>
					<span v-if="errors.memory" class="ap-error">{{ errors.memory }}</span>
				</div>
			</div>
			<div v-if="canManage" class="ap-actions">
				<button
					type="submit"
					class="ap-btn ap-btn-primary ap-btn-sm"
					:disabled="busy !== '' || form.instances < 1 || form.instances > maxInstances || form.memory < 64 || form.memory > memoryMax || (form.instances > 1 && form.kind === 'script')"
				>
					<span v-if="busy === 'scale'" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="save" />{{ t('Save') }}
				</button>
			</div>
		</form>
	</div>
</template>
