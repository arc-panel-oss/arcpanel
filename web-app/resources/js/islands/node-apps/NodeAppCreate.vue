<script setup>
// Create wizard of a Node.js app (E14 spec §7): domain → start command →
// runtime → port and name → template → create, then install and build.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createTaskPoller } from '../shared/task-poller.js';
import { appUrl, createApi, fieldErrors } from './api.js';
import { composeStart, defaultName, isAppName, isRelativePath, isStart, templateFor } from './model.js';
import TaskPanel from './TaskPanel.vue';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const steps = [t('Domain'), t('Start command'), t('Runtime'), t('Port and name'), t('Template')];
const step = ref(0);
const loading = ref(true);
const busy = ref(false);
const options = ref({ domains: [], templates: [], runtimes: [], default: '', hasRoom: true, maxInstances: 1 });
const proposal = ref(null);
const errors = ref({});
const created = ref(null);
const task = ref(props.initial.task ?? {});

const form = reactive({
	domain: props.initial.domain ?? '',
	cwd: 'public_html',
	kind: 'file',
	file: 'server.js',
	script: 'start',
	version: 'default',
	port: 'auto',
	portNumber: '',
	instances: 1,
	name: '',
	template: '',
});

const start = computed(() => composeStart({ kind: form.kind, value: form.kind === 'script' ? form.script : form.file }));
const scripts = computed(() => proposal.value?.scripts ?? []);
const runtimeLabel = computed(() => (form.version === 'default' ? t('Default (%s)', options.value.default ? `v${options.value.default}` : '—') : `v${form.version}`));

function stepValid(index) {
	switch (index) {
		case 0:
			return options.value.domains.includes(form.domain);
		case 1:
			return isRelativePath(form.cwd) && isStart(start.value);
		case 2:
			return form.version === 'default' ? options.value.default !== '' : options.value.runtimes.some((r) => r.version === form.version);
		case 3:
			return (form.port === 'auto' || /^\d{4,5}$/.test(form.portNumber)) && isAppName(form.name) && form.instances >= 1 && form.instances <= options.value.maxInstances && !(form.instances > 1 && form.kind === 'script');
		default:
			return form.template !== '';
	}
}

async function detect() {
	if (!form.domain) {
		return;
	}
	try {
		proposal.value = await api.call('GET', `/detect/${encodeURIComponent(form.domain)}?cwd=${encodeURIComponent(form.cwd)}`);
		const parsed = proposal.value.start.startsWith('npm:') ? { kind: 'script', value: proposal.value.start.slice(4) } : { kind: 'file', value: proposal.value.start };
		form.kind = parsed.kind;
		if (parsed.kind === 'script') {
			form.script = parsed.value;
		} else {
			form.file = parsed.value;
		}
		form.template = templateFor(proposal.value, options.value.templates);
	} catch (error) {
		api.fail(error);
	}
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
	try {
		const data = await api.call('POST', '', {
			domain: form.domain,
			start: start.value,
			cwd: form.cwd,
			version: form.version,
			port: form.port === 'auto' ? 'auto' : form.portNumber,
			instances: Number(form.instances),
			template: form.template,
			name: form.name,
		});
		created.value = data.app;
		task.value = data.task ?? {};
		api.saved(t('The app of %s runs.', data.app.domain));
	} catch (error) {
		errors.value = fieldErrors(error);
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

const poller = createTaskPoller({
	fetchTask: async () => (await api.call('GET', `/${encodeURIComponent(created.value.domain)}`)).task,
	onUpdate: (value) => {
		task.value = value;
	},
	onFinish: (value) => {
		api.toast(value.status === 'done' ? 'ok' : 'error', value.status === 'done' ? t('Done') : t('Failed'));
	},
	onError: () => {},
});

async function startTask(path, body) {
	busy.value = true;
	try {
		const data = await api.call('POST', `/${encodeURIComponent(created.value.domain)}${path}`, body);
		task.value = data.task;
		poller.start();
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

watch(
	() => form.domain,
	(domain) => {
		form.name = domain ? defaultName(domain) : '';
		detect();
	},
);

onMounted(async () => {
	try {
		options.value = await api.call('GET', '/options');
		if (!options.value.domains.includes(form.domain)) {
			form.domain = options.value.domains[0] ?? '';
		} else {
			form.name = defaultName(form.domain);
			await detect();
		}
		form.template ||= templateFor(null, options.value.templates);
	} catch (error) {
		api.fail(error);
	} finally {
		loading.value = false;
	}
});

onBeforeUnmount(() => poller.stop());
</script>

<template>
	<div class="ap-stack">
		<p v-if="loading" class="muted">{{ t('Loading…') }}</p>

		<section v-else-if="created" class="ap-card" aria-labelledby="node-created-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="check-circle" />
					<h2 id="node-created-title">{{ t('The app of %s runs.', created.domain) }}</h2>
				</div>
				<div class="ap-actions">
					<a class="ap-btn ap-btn-sm" :href="created.url" target="_blank" rel="noopener"><ApIcon name="external" />{{ t('Open the site') }}</a>
					<a class="ap-btn ap-btn-primary ap-btn-sm" :href="appUrl(pages.show, created.domain)"><ApIcon name="chevron-right" />{{ t('Open the app') }}</a>
				</div>
			</div>
			<p>{{ t('Next, install the dependencies of package.json and build the app if it needs a build.') }}</p>
			<div class="ap-actions">
				<button type="button" class="ap-btn" :disabled="busy || task.running || !proposal?.packageJson" @click="startTask('/install')">
					<ApIcon name="download" />{{ t('Install dependencies') }}
				</button>
				<button type="button" class="ap-btn" :disabled="busy || task.running || !scripts.includes('build')" @click="startTask('/build', { script: 'build' })">
					<ApIcon name="code" />{{ t('Run build') }}
				</button>
			</div>
			<TaskPanel v-if="task.id" :task="task" :t="t" />
		</section>

		<section v-else-if="options.domains.length === 0" class="ap-card">
			<div class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="globe" /></div>
				<h3>{{ t('Every web domain has an app already.') }}</h3>
				<p>{{ t('Add a web domain first; an app runs for one domain.') }}</p>
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
					<label class="ap-label" for="app-domain">{{ t('Web domain') }}</label>
					<div class="ap-select-wrap">
						<select id="app-domain" v-model="form.domain" class="ap-select">
							<option v-for="domain in options.domains" :key="domain" :value="domain">{{ domain }}</option>
						</select>
						<ApIcon name="chevron-down" size="sm" />
					</div>
					<span class="ap-hint">{{ t('The domain gets a Node.js template that passes its requests to the app.') }}</span>
					<span v-if="errors.domain" class="ap-error">{{ errors.domain }}</span>
				</div>
			</fieldset>

			<fieldset v-show="step === 1" class="ap-fieldset-plain">
				<legend class="sr-only">{{ steps[1] }}</legend>
				<div class="ap-field" :class="{ 'is-invalid': form.cwd && !isRelativePath(form.cwd) }">
					<label class="ap-label" for="app-cwd">{{ t('Working directory') }}</label>
					<div class="ap-row">
						<input id="app-cwd" v-model="form.cwd" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						<button type="button" class="ap-btn ap-btn-sm" @click="detect"><ApIcon name="search" />{{ t('Read package.json') }}</button>
					</div>
					<span class="ap-hint">{{ t('Relative to the folder of the domain, e.g. public_html or app.') }}</span>
				</div>
				<fieldset class="ap-field ap-fieldset">
					<legend class="ap-label">{{ t('Start with') }}</legend>
					<div class="ap-row ap-radio-row">
						<label class="ap-check"><input v-model="form.kind" type="radio" value="file" />{{ t('A file') }}</label>
						<label class="ap-check"><input v-model="form.kind" type="radio" value="script" />{{ t('A script of package.json') }}</label>
					</div>
				</fieldset>
				<div v-if="form.kind === 'file'" class="ap-field" :class="{ 'is-invalid': errors.start || !isStart(start) }">
					<label class="ap-label" for="app-file">{{ t('Start file') }}</label>
					<input id="app-file" v-model="form.file" class="ap-input ap-input-mono" type="text" spellcheck="false" placeholder="server.js" />
					<span v-if="errors.start" class="ap-error">{{ errors.start }}</span>
				</div>
				<div v-else class="ap-field" :class="{ 'is-invalid': errors.start }">
					<label class="ap-label" for="app-script">{{ t('Script') }}</label>
					<div v-if="scripts.length > 0" class="ap-select-wrap">
						<select id="app-script" v-model="form.script" class="ap-select">
							<option v-for="name in scripts" :key="name" :value="name">{{ name }}</option>
						</select>
						<ApIcon name="chevron-down" size="sm" />
					</div>
					<input v-else id="app-script" v-model="form.script" class="ap-input ap-input-mono" type="text" spellcheck="false" />
					<span class="ap-hint">{{ t('Next.js and Nuxt start with the start script (next start, nuxt start).') }}</span>
					<span v-if="errors.start" class="ap-error">{{ errors.start }}</span>
				</div>
				<p v-if="proposal && !proposal.packageJson" class="muted">{{ t('No package.json in this folder yet; you can upload the code later and start the app then.') }}</p>
			</fieldset>

			<fieldset v-show="step === 2" class="ap-field ap-fieldset">
				<legend class="ap-label">{{ t('Runtime') }}</legend>
				<div class="ap-stack">
					<label class="ap-check">
						<input v-model="form.version" type="radio" value="default" :disabled="!options.default" />
						<span>{{ t('Default (%s)', options.default ? `v${options.default}` : '—') }} <span class="muted">· {{ t('follows the default runtime of the account') }}</span></span>
					</label>
					<label v-for="runtime in options.runtimes" :key="runtime.version" class="ap-check">
						<input v-model="form.version" type="radio" :value="runtime.version" />
						<span class="mono">v{{ runtime.version }}</span>
					</label>
				</div>
				<p v-if="options.runtimes.length === 0" class="muted">
					{{ t('Install a Node.js version first.') }} <a v-if="pages.node" :href="pages.node">{{ t('Open Runtimes') }}</a>
				</p>
			</fieldset>

			<fieldset v-show="step === 3" class="ap-fieldset-plain">
				<legend class="sr-only">{{ steps[3] }}</legend>
				<fieldset class="ap-field ap-fieldset">
					<legend class="ap-label">{{ t('Port') }}</legend>
					<div class="ap-row ap-radio-row">
						<label class="ap-check"><input v-model="form.port" type="radio" value="auto" />{{ t('Automatic (a free port of your pool)') }}</label>
						<label class="ap-check"><input v-model="form.port" type="radio" value="manual" />{{ t('A port of my pool') }}</label>
					</div>
				</fieldset>
				<div v-if="form.port === 'manual'" class="ap-field" :class="{ 'is-invalid': errors.port }">
					<label class="ap-label" for="app-port">{{ t('Port number') }}</label>
					<input id="app-port" v-model="form.portNumber" class="ap-input ap-input-mono" type="text" inputmode="numeric" />
					<span v-if="errors.port" class="ap-error">{{ errors.port }}</span>
				</div>
				<div class="ap-form-grid">
					<div class="ap-field" :class="{ 'is-invalid': errors.name || (form.name && !isAppName(form.name)) }">
						<label class="ap-label" for="app-name">{{ t('Process name') }}</label>
						<input id="app-name" v-model="form.name" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						<span class="ap-hint">{{ t('The name in pm2: lower-case letters, digits, dots, - and _.') }}</span>
						<span v-if="errors.name" class="ap-error">{{ errors.name }}</span>
					</div>
					<div class="ap-field">
						<label class="ap-label" for="app-instances">{{ t('Instances') }}</label>
						<input id="app-instances" v-model.number="form.instances" class="ap-input ap-input-mono" type="number" min="1" :max="options.maxInstances" />
						<span class="ap-hint">{{ t('More than one runs the app in cluster mode (at most %d).', options.maxInstances) }}</span>
					</div>
				</div>
			</fieldset>

			<fieldset v-show="step === 4" class="ap-field ap-fieldset">
				<legend class="ap-label">{{ t('Template') }}</legend>
				<div class="ap-picker">
					<label v-for="template in options.templates" :key="template.name" class="ap-pick">
						<input v-model="form.template" type="radio" name="app-template" :value="template.name" />
						<span class="ap-pick-logo">JS</span>
						<b>{{ template.title }}</b>
						<span>{{ template.description }}</span>
						<span class="ap-pick-foot"><small class="mono">{{ template.name }}</small></span>
					</label>
				</div>
				<dl class="ap-dl">
					<dt>{{ t('Domain') }}</dt>
					<dd class="mono">{{ form.domain }}</dd>
					<dt>{{ t('Start') }}</dt>
					<dd class="mono">{{ form.cwd }}/{{ form.kind === 'script' ? `npm run ${form.script}` : form.file }}</dd>
					<dt>{{ t('Runtime') }}</dt>
					<dd class="mono">{{ runtimeLabel }}</dd>
					<dt>{{ t('Port') }}</dt>
					<dd class="mono">{{ form.port === 'auto' ? t('automatic') : form.portNumber }}</dd>
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
						<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="check" />{{ t('Create app') }}
					</button>
				</div>
			</div>
		</form>
	</div>
</template>
