<script setup>
// Create wizard of an app process (E42 spec §9): domain and kind → preset
// (the detected ones first) → command and working directory → resources
// and review. The preset's command is sent only when it was changed, so the
// server fills %port% and %workers% itself.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { createApi, fieldErrors } from '../node-apps/api.js';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { defaultMemory, defaultName, isCommand, isCpus, isProcessName, isRelativePath, presetsFor, processUrl } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const steps = [t('Domain'), t('Preset'), t('Command'), t('Resources')];
const step = ref(0);
const loading = ref(true);
const busy = ref(false);
const options = ref({ domains: [], presets: [], hasRoom: true, maxMemory: 0, defaultMemory: 512, maxCpus: '1' });
const errors = ref({});

const form = reactive({
	domain: props.initial.domain ?? '',
	kind: 'web',
	preset: '',
	cwd: 'public_html',
	start: '',
	startEdited: false,
	template: '',
	name: 'web',
	memory: 512,
	cpus: '1',
});

const domainRow = computed(() => options.value.domains.find((row) => row.domain === form.domain));
const presets = computed(() => presetsFor(options.value.presets, form.kind));
const preset = computed(() => options.value.presets.find((row) => row.name === form.preset) ?? null);
const isCustom = computed(() => form.preset === 'custom');
const webTemplates = computed(() =>
	[...new Set(options.value.presets.filter((row) => row.kind === 'web' && row.template !== '' && row.templateAvailable !== 'no').map((row) => row.template))].sort(),
);
const maxCpus = computed(() => (options.value.maxCpus === 'unlimited' ? null : Number(options.value.maxCpus)));

function stepValid(index) {
	switch (index) {
		case 0:
			return Boolean(domainRow.value) && (form.kind === 'worker' || !domainRow.value.hasWeb);
		case 1:
			return preset.value !== null && (preset.value.runtimeMissing ?? '') === '';
		case 2:
			return (
				isRelativePath(form.cwd) &&
				isProcessName(form.name) &&
				(isCustom.value || form.startEdited ? isCommand(form.start) : true) &&
				(!isCustom.value || form.kind === 'worker' || form.template !== '')
			);
		default:
			return (
				Number.isInteger(Number(form.memory)) &&
				Number(form.memory) >= 32 &&
				(options.value.maxMemory <= 0 || Number(form.memory) <= options.value.maxMemory) &&
				isCpus(form.cpus) &&
				(maxCpus.value === null || Number(form.cpus) <= maxCpus.value)
			);
	}
}

async function load() {
	const query = form.domain ? `?domain=${encodeURIComponent(form.domain)}&cwd=${encodeURIComponent(isRelativePath(form.cwd) ? form.cwd : 'public_html')}` : '';
	try {
		options.value = await api.call('GET', `/options${query}`);
	} catch (error) {
		api.fail(error);
	}
}

function choosePreset(name) {
	form.preset = name;
	const row = options.value.presets.find((p) => p.name === name);
	form.start = row && name !== 'custom' ? row.start : '';
	form.startEdited = false;
	form.name = defaultName(form.kind, name);
}

function next() {
	if (stepValid(step.value)) {
		step.value = Math.min(step.value + 1, steps.length - 1);
	}
}

function back() {
	step.value = Math.max(step.value - 1, 0);
}

async function create() {
	if (!steps.every((_, index) => stepValid(index))) {
		return;
	}
	busy.value = true;
	errors.value = {};
	const body = {
		domain: form.domain,
		name: form.name,
		preset: form.preset,
		cwd: form.cwd,
		memory_mb: Number(form.memory),
		cpus: String(form.cpus),
	};
	if (isCustom.value || form.startEdited) {
		body.start = form.start;
	}
	if (isCustom.value) {
		body.kind = form.kind;
		if (form.kind === 'web') {
			body.template = form.template;
		}
	}
	try {
		const data = await api.call('POST', '', body);
		api.saved(t('%s runs.', `${data.process.domain}/${data.process.name}`));
		window.location.assign(processUrl(pages.show, data.process));
	} catch (error) {
		errors.value = fieldErrors(error);
		api.fail(error);
		busy.value = false;
	}
}

watch(
	() => form.domain,
	async () => {
		if (domainRow.value?.hasWeb) {
			form.kind = 'worker';
		}
		await load();
	},
);
watch(
	() => form.kind,
	(kind) => {
		form.preset = '';
		form.name = defaultName(kind, '');
	},
);

onMounted(async () => {
	await load();
	if (!form.domain && options.value.domains.length > 0) {
		form.domain = options.value.domains[0].domain;
	}
	form.memory = defaultMemory(options.value);
	loading.value = false;
});
</script>

<template>
	<div class="ap-stack">
		<p v-if="loading" class="muted">{{ t('Loading…') }}</p>

		<section v-else-if="!options.hasRoom" class="ap-card">
			<div class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="terminal" /></div>
				<h3>{{ t('Your package allows no more processes.') }}</h3>
				<p><a :href="pages.list">{{ t('Back') }}</a></p>
			</div>
		</section>

		<section v-else-if="options.domains.length === 0" class="ap-card">
			<div class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="globe" /></div>
				<h3>{{ t('Add a web domain first; a process runs for one domain.') }}</h3>
			</div>
		</section>

		<form v-else class="ap-card ap-wizard" novalidate @submit.prevent="step === steps.length - 1 ? create() : next()">
			<ol class="ap-steps">
				<li v-for="(title, index) in steps" :key="title" :class="{ 'is-done': step > index, 'is-current': step === index }" :aria-current="step === index ? 'step' : undefined">
					<span class="ap-step-num"><span v-if="step <= index">{{ index + 1 }}</span><ApIcon v-else name="check" size="sm" /></span>{{ title }}
				</li>
			</ol>

			<fieldset v-show="step === 0" class="ap-fieldset-plain">
				<legend class="sr-only">{{ steps[0] }}</legend>
				<div class="ap-field" :class="{ 'is-invalid': errors.domain }">
					<label class="ap-label" for="process-domain">{{ t('Web domain') }}</label>
					<div class="ap-select-wrap">
						<select id="process-domain" v-model="form.domain" class="ap-select">
							<option v-for="row in options.domains" :key="row.domain" :value="row.domain">{{ row.domain }}</option>
						</select>
						<ApIcon name="chevron-down" size="sm" />
					</div>
					<span v-if="errors.domain" class="ap-error">{{ errors.domain }}</span>
				</div>
				<fieldset class="ap-field ap-fieldset">
					<legend class="ap-label">{{ t('Kind') }}</legend>
					<div class="ap-stack">
						<label class="ap-check">
							<input v-model="form.kind" type="radio" value="web" :disabled="domainRow?.hasWeb" />
							<span>{{ t('Web process') }} <span class="muted">· {{ t('serves the domain on a port of your pool; the domain gets the template of the preset') }}</span></span>
						</label>
						<label class="ap-check">
							<input v-model="form.kind" type="radio" value="worker" />
							<span>{{ t('Worker') }} <span class="muted">· {{ t('runs in the background without a port, such as a queue worker') }}</span></span>
						</label>
					</div>
					<span v-if="domainRow?.hasWeb" class="ap-hint">{{ t('This domain has a web process already.') }}</span>
				</fieldset>
			</fieldset>

			<fieldset v-show="step === 1" class="ap-field ap-fieldset">
				<legend class="ap-label">{{ t('Preset') }}</legend>
				<div class="ap-picker">
					<label v-for="row in presets" :key="row.name" class="ap-pick" :class="{ 'is-disabled': row.runtimeMissing }">
						<input type="radio" name="process-preset" :value="row.name" :checked="form.preset === row.name" :disabled="Boolean(row.runtimeMissing)" @change="choosePreset(row.name)" />
						<b>{{ row.title }}</b>
						<span v-if="row.runtimeMissing" class="ap-error">{{ row.runtimeMissing }}</span>
						<span v-else-if="row.detected === 'yes'">{{ t('Found in %s', form.cwd) }}</span>
						<span class="ap-pick-foot"><small class="mono">{{ row.name }}</small></span>
					</label>
				</div>
				<div class="ap-field">
					<label class="ap-label" for="process-cwd-detect">{{ t('Working directory') }}</label>
					<div class="ap-row">
						<input id="process-cwd-detect" v-model="form.cwd" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						<button type="button" class="ap-btn ap-btn-sm" :disabled="!isRelativePath(form.cwd)" @click="load"><ApIcon name="search" />{{ t('Detect') }}</button>
					</div>
					<span class="ap-hint">{{ t('Relative to the folder of the domain, e.g. public_html or app.') }}</span>
				</div>
			</fieldset>

			<fieldset v-show="step === 2" class="ap-fieldset-plain">
				<legend class="sr-only">{{ steps[2] }}</legend>
				<div class="ap-field" :class="{ 'is-invalid': errors.start || ((isCustom || form.startEdited) && form.start !== '' && !isCommand(form.start)) }">
					<label class="ap-label" for="process-start">{{ t('Command') }}</label>
					<textarea
						id="process-start"
						v-model="form.start"
						class="ap-input ap-input-mono"
						rows="3"
						spellcheck="false"
						@input="form.startEdited = true"
					/>
					<span class="ap-hint">{{ t('Runs as your user in the working directory, without a shell: quotes group words, nothing is expanded. %port% is the port of a web process.') }}</span>
					<span v-if="errors.start" class="ap-error">{{ errors.start }}</span>
				</div>
				<div class="ap-form-grid">
					<div class="ap-field" :class="{ 'is-invalid': errors.cwd || !isRelativePath(form.cwd) }">
						<label class="ap-label" for="process-cwd">{{ t('Working directory') }}</label>
						<input id="process-cwd" v-model="form.cwd" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						<span v-if="errors.cwd" class="ap-error">{{ errors.cwd }}</span>
					</div>
					<div class="ap-field" :class="{ 'is-invalid': errors.name || !isProcessName(form.name) }">
						<label class="ap-label" for="process-name">{{ t('Name') }}</label>
						<input id="process-name" v-model="form.name" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						<span class="ap-hint">{{ t('Lower-case letters, digits and -, starting with a letter.') }}</span>
						<span v-if="errors.name" class="ap-error">{{ errors.name }}</span>
					</div>
				</div>
				<div v-if="isCustom && form.kind === 'web'" class="ap-field" :class="{ 'is-invalid': errors.template }">
					<label class="ap-label" for="process-template">{{ t('Template') }}</label>
					<div class="ap-select-wrap">
						<select id="process-template" v-model="form.template" class="ap-select">
							<option v-for="name in webTemplates" :key="name" :value="name">{{ name }}</option>
						</select>
						<ApIcon name="chevron-down" size="sm" />
					</div>
					<span class="ap-hint">{{ t('The domain passes its requests to the port of the process through this template.') }}</span>
					<span v-if="errors.template" class="ap-error">{{ errors.template }}</span>
				</div>
			</fieldset>

			<fieldset v-show="step === 3" class="ap-fieldset-plain">
				<legend class="sr-only">{{ steps[3] }}</legend>
				<div class="ap-form-grid">
					<div class="ap-field" :class="{ 'is-invalid': errors.memory_mb || !stepValid(3) }">
						<label class="ap-label" for="process-memory">{{ t('Memory (MB)') }}</label>
						<input id="process-memory" v-model.number="form.memory" class="ap-input ap-input-mono" type="number" min="32" :max="options.maxMemory || undefined" />
						<span class="ap-hint">{{ t('The process is restarted when it uses more (at most %d MB).', options.maxMemory) }}</span>
						<span v-if="errors.memory_mb" class="ap-error">{{ errors.memory_mb }}</span>
					</div>
					<div class="ap-field" :class="{ 'is-invalid': errors.cpus }">
						<label class="ap-label" for="process-cpus">{{ t('CPUs') }}</label>
						<input id="process-cpus" v-model="form.cpus" class="ap-input ap-input-mono" type="text" inputmode="decimal" />
						<span class="ap-hint">{{ t('Such as 1 or 0.5 (at most %s).', options.maxCpus) }}</span>
						<span v-if="errors.cpus" class="ap-error">{{ errors.cpus }}</span>
					</div>
				</div>
				<dl class="ap-dl">
					<dt>{{ t('Process') }}</dt>
					<dd class="mono">{{ form.domain }}/{{ form.name }}</dd>
					<dt>{{ t('Preset') }}</dt>
					<dd class="mono">{{ form.preset }}</dd>
					<dt>{{ t('Command') }}</dt>
					<dd class="mono">{{ form.start }}</dd>
					<dt>{{ t('Working directory') }}</dt>
					<dd class="mono">{{ form.cwd }}</dd>
				</dl>
			</fieldset>

			<div class="ap-between">
				<span class="muted">{{ t('Step %d of %d', step + 1, steps.length) }}</span>
				<div class="ap-actions">
					<a class="ap-btn ap-btn-ghost" :href="pages.list">{{ t('Cancel') }}</a>
					<button v-if="step > 0" type="button" class="ap-btn ap-btn-ghost" @click="back">{{ t('Back') }}</button>
					<button v-if="step < steps.length - 1" type="submit" class="ap-btn ap-btn-primary" :disabled="!stepValid(step)">
						<ApIcon name="arrow" />{{ t('Continue') }}
					</button>
					<button v-else type="submit" class="ap-btn ap-btn-primary" :disabled="busy || !stepValid(step)">
						<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="check" />{{ t('Add process') }}
					</button>
				</div>
			</div>
		</form>
	</div>
</template>
