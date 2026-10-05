<script setup>
// A catalog app (E4 spec §7): what it is and needs, then the install form
// generated from its variables: name, web domain, values. Installing
// starts a task and opens the page of the new app.
import { computed, reactive, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createApi, fieldErrors, pageUrl } from './api.js';
import { defaultAppName, formErrors, generateSecret, isAppName } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;
const app = props.initial.catalogApp;
const abilities = props.initial.abilities ?? {};
const domains = props.initial.domains ?? [];
const names = props.initial.names ?? [];

const name = ref(defaultAppName(app.slug, names));
const domain = ref(domains[0] ?? '');
const values = reactive(Object.fromEntries(app.variables.map((variable) => [variable.name, variable.random ? '' : variable.default])));
const shown = reactive({});
const errors = ref({});
const busy = ref(false);

const canInstall = computed(() => abilities.manage && props.initial.hasRoom && (!app.web || domains.length > 0));
const reason = computed(() => {
	if (!abilities.engine) {
		return t('Docker apps are not available on this server.');
	}
	if (!abilities.available) {
		return t('Docker apps are not included in your package.');
	}
	if (!props.initial.hasRoom) {
		return t('Your package allows no more apps.');
	}
	if (app.web && domains.length === 0) {
		return t('Every web domain has an app already.');
	}
	return '';
});

function validate() {
	const found = {};
	if (!isAppName(name.value)) {
		found.name = t('2 to 32 lower-case letters, digits and dashes, starting with a letter.');
	} else if (names.includes(name.value)) {
		found.name = t('An app with the same name exists.');
	}
	for (const [key, message] of Object.entries(formErrors(app.variables, values))) {
		found[`vars.${key}`] = t(message);
	}
	errors.value = found;
	return Object.keys(found).length === 0;
}

async function install() {
	if (!validate()) {
		return;
	}
	busy.value = true;
	try {
		await api.call('POST', '/apps', {
			slug: app.slug,
			name: name.value,
			domain: app.web ? domain.value : '',
			version: app.version,
			vars: { ...values },
		});
		api.toast('ok', t('The installation continues in the background.'));
		window.location.assign(pageUrl(pages.show, name.value));
	} catch (error) {
		errors.value = fieldErrors(error);
		api.fail(error);
		busy.value = false;
	}
}

function generate(variable) {
	values[variable.name] = generateSecret(24);
	shown[variable.name] = true;
}
</script>

<template>
	<div class="ap-stack">
		<section class="ap-card" aria-labelledby="docker-detail-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<span class="ap-pick-logo">
						<img v-if="app.icon" :src="app.icon" alt="" />
						<template v-else>{{ app.name.slice(0, 2) }}</template>
					</span>
					<h2 id="docker-detail-title">{{ app.name }}</h2>
					<span class="muted mono">{{ app.version }}</span>
					<ApBadge>{{ t(app.category) }}</ApBadge>
				</div>
				<div class="ap-actions">
					<a v-if="app.homepage" class="ap-btn ap-btn-ghost ap-btn-sm" :href="app.homepage" target="_blank" rel="noopener">
						<ApIcon name="external" />{{ t('Homepage') }}
					</a>
					<a class="ap-btn ap-btn-ghost ap-btn-sm" :href="pages.store"><ApIcon name="chevron-left" />{{ t('Store') }}</a>
				</div>
			</div>
			<p>{{ app.summary }}</p>
			<p v-for="(paragraph, index) in app.description.split(/\n{2,}/).filter(Boolean)" :key="index" class="muted">{{ paragraph }}</p>
			<dl class="ap-dl">
				<dt>{{ t('Requirements') }}</dt>
				<dd class="mono">{{ t('Memory') }} {{ app.requirements.memory }} · {{ t('Disk') }} {{ app.requirements.disk }}</dd>
				<dt>{{ t('Limits') }}</dt>
				<dd class="mono">{{ app.limits.cpus }} {{ t('CPUs') }} · {{ app.limits.memory }}</dd>
				<dt>{{ t('License') }}</dt>
				<dd>{{ app.license || '—' }}</dd>
				<template v-if="app.ports.length > 0">
					<dt>{{ t('Ports') }}</dt>
					<dd>
						<span v-for="port in app.ports" :key="port.name" class="mono">{{ port.name }} → {{ port.container }} </span>
						<div class="muted">{{ t('Ports are private to the server unless made public on the Ports page.') }}</div>
					</dd>
				</template>
				<dt>{{ t('Backups') }}</dt>
				<dd class="muted">
					{{ app.dumps ? t('The nightly backup includes the data folder of the app; databases are dumped into data/.dumps 15 minutes before it.') : t('This app has no database to dump: its data folder is backed up as it is.') }}
				</dd>
			</dl>
		</section>

		<form class="ap-card" aria-labelledby="docker-install-title" novalidate @submit.prevent="install">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="download" />
					<h2 id="docker-install-title">{{ t('Install %s', app.name) }}</h2>
				</div>
			</div>
			<div v-if="reason" class="ap-alert is-warn" role="status">
				<ApIcon name="alert" />
				<div class="ap-alert-body">
					<p>{{ reason }}</p>
					<a v-if="app.web && domains.length === 0 && abilities.manage" :href="pages.domains">{{ t('Add a web domain first.') }}</a>
				</div>
			</div>
			<fieldset class="ap-fieldset" :disabled="!canInstall || busy">
				<div class="ap-form-grid">
					<div class="ap-field">
						<label class="ap-label" for="docker-name">{{ t('App name') }}</label>
						<input id="docker-name" v-model="name" class="ap-input mono" required :aria-invalid="errors.name ? 'true' : 'false'" aria-describedby="docker-name-hint" />
						<span id="docker-name-hint" class="ap-hint">{{ t('2 to 32 lower-case letters, digits and dashes, starting with a letter.') }}</span>
						<span v-if="errors.name" class="ap-error" role="alert"><ApIcon name="x-circle" size="sm" />{{ errors.name }}</span>
					</div>
					<div v-if="app.web" class="ap-field">
						<label class="ap-label" for="docker-domain">{{ t('Web domain') }}</label>
						<select id="docker-domain" v-model="domain" class="ap-input" required :aria-invalid="errors.domain ? 'true' : 'false'">
							<option v-for="option in domains" :key="option" :value="option">{{ option }}</option>
						</select>
						<span v-if="errors.domain" class="ap-error" role="alert"><ApIcon name="x-circle" size="sm" />{{ errors.domain }}</span>
					</div>
				</div>
				<div v-if="app.variables.length > 0" class="ap-form-grid">
					<div v-for="variable in app.variables" :key="variable.name" class="ap-field">
						<label class="ap-label" :for="`docker-var-${variable.name}`">{{ variable.label }}</label>
						<select
							v-if="variable.type === 'enum'"
							:id="`docker-var-${variable.name}`"
							v-model="values[variable.name]"
							class="ap-input"
						>
							<option v-for="option in variable.options" :key="option" :value="option">{{ option }}</option>
						</select>
						<select v-else-if="variable.type === 'bool'" :id="`docker-var-${variable.name}`" v-model="values[variable.name]" class="ap-input">
							<option value="true">true</option>
							<option value="false">false</option>
						</select>
						<div v-else-if="variable.type === 'password'" class="ap-row">
							<input
								:id="`docker-var-${variable.name}`"
								v-model="values[variable.name]"
								class="ap-input mono"
								:type="shown[variable.name] ? 'text' : 'password'"
								autocomplete="new-password"
								:placeholder="variable.random ? t('Generate') : ''"
								:aria-invalid="errors[`vars.${variable.name}`] ? 'true' : 'false'"
							/>
							<button type="button" class="ap-btn ap-btn-sm" @click="generate(variable)"><ApIcon name="key" />{{ t('Generate') }}</button>
							<button
								type="button"
								class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
								:aria-label="shown[variable.name] ? t('Hide') : t('Show')"
								:aria-pressed="shown[variable.name] ? 'true' : 'false'"
								@click="shown[variable.name] = !shown[variable.name]"
							>
								<ApIcon name="eye" />
							</button>
						</div>
						<input
							v-else
							:id="`docker-var-${variable.name}`"
							v-model="values[variable.name]"
							class="ap-input"
							:class="{ mono: variable.type !== 'string' }"
							:type="variable.type === 'int' ? 'number' : variable.type === 'email' ? 'email' : variable.type === 'url' ? 'url' : 'text'"
							:aria-invalid="errors[`vars.${variable.name}`] ? 'true' : 'false'"
						/>
						<span v-if="variable.immutable" class="ap-hint">{{ t('Immutable: set at installation.') }}</span>
						<span v-if="errors[`vars.${variable.name}`]" class="ap-error" role="alert">
							<ApIcon name="x-circle" size="sm" />{{ errors[`vars.${variable.name}`] }}
						</span>
					</div>
				</div>
			</fieldset>
			<div class="ap-form-actions">
				<button type="submit" class="ap-btn ap-btn-primary" :disabled="!canInstall || busy">
					<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="download" />{{ t('Install') }}
				</button>
				<a class="ap-btn ap-btn-ghost" :href="pages.store">{{ t('Cancel') }}</a>
			</div>
		</form>
	</div>
</template>
