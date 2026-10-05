<script setup>
// Settings of an app (E4 spec §7): its variables (secrets write-only,
// immutable ones read-only), its limits and its web domain. Each part
// saves on its own; the app starts again with the new values.
import { computed, onMounted, reactive, ref } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { appPath, fieldErrors } from './api.js';
import { generateSecret, varError } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	app: { type: Object, required: true },
	domains: { type: Array, default: () => [] },
	canManage: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['update']);

const t = props.t;
const variables = ref([]);
const values = reactive({});
const shown = reactive({});
const errors = ref({});
const busy = ref('');
const limits = reactive({ cpus: props.app.cpus, memory: props.app.memory });
const domain = ref('');

const loaded = computed(() => variables.value.length > 0);

async function load() {
	try {
		variables.value = (await props.api.call('GET', appPath(props.app.name, '/env'))).variables;
		for (const variable of variables.value) {
			values[variable.name] = variable.secret ? '' : variable.value;
		}
	} catch (error) {
		props.api.fail(error);
	}
}

async function saveVariables() {
	const found = {};
	const changed = {};
	for (const variable of variables.value) {
		const value = values[variable.name] ?? '';
		if (variable.immutable || (!variable.secret && value === variable.value) || (variable.secret && value === '')) {
			continue;
		}
		const problem = varError(variable, value);
		if (problem) {
			found[`vars.${variable.name}`] = t(problem);
		}
		changed[variable.name] = value;
	}
	errors.value = found;
	if (Object.keys(found).length > 0 || Object.keys(changed).length === 0) {
		return;
	}
	busy.value = 'vars';
	try {
		variables.value = (await props.api.call('PUT', appPath(props.app.name, '/env'), { vars: changed })).variables;
		for (const variable of variables.value) {
			values[variable.name] = variable.secret ? '' : variable.value;
		}
		props.api.saved();
	} catch (error) {
		errors.value = fieldErrors(error);
		props.api.fail(error);
	} finally {
		busy.value = '';
	}
}

async function save(part, body) {
	busy.value = part;
	try {
		emit('update', await props.api.call('PUT', appPath(props.app.name, `/${part}`), body));
		props.api.saved();
	} catch (error) {
		errors.value = fieldErrors(error);
		props.api.fail(error);
	} finally {
		busy.value = '';
	}
}

onMounted(load);
</script>

<template>
	<div class="ap-stack">
		<form class="ap-stack" novalidate @submit.prevent="saveVariables">
			<h3>{{ t('Variables') }}</h3>
			<p v-if="!loaded" class="muted">{{ t('Loading…') }}</p>
			<fieldset v-else class="ap-fieldset" :disabled="!canManage || busy !== ''">
				<div class="ap-form-grid">
					<div v-for="variable in variables" :key="variable.name" class="ap-field">
						<label class="ap-label" :for="`set-var-${variable.name}`">{{ variable.label }}</label>
						<select
							v-if="variable.type === 'enum' || variable.type === 'bool'"
							:id="`set-var-${variable.name}`"
							v-model="values[variable.name]"
							class="ap-input"
							:disabled="variable.immutable"
						>
							<option v-for="option in variable.type === 'bool' ? ['true', 'false'] : variable.options" :key="option" :value="option">{{ option }}</option>
						</select>
						<div v-else-if="variable.secret" class="ap-row">
							<input
								:id="`set-var-${variable.name}`"
								v-model="values[variable.name]"
								class="ap-input mono"
								:type="shown[variable.name] ? 'text' : 'password'"
								autocomplete="new-password"
								:disabled="variable.immutable"
								:placeholder="variable.set ? '••••••••' : ''"
							/>
							<button v-if="!variable.immutable" type="button" class="ap-btn ap-btn-sm" @click="values[variable.name] = generateSecret(24); shown[variable.name] = true">
								<ApIcon name="key" />{{ t('Generate') }}
							</button>
						</div>
						<input
							v-else
							:id="`set-var-${variable.name}`"
							v-model="values[variable.name]"
							class="ap-input"
							:class="{ mono: variable.type !== 'string' }"
							:disabled="variable.immutable"
						/>
						<span v-if="variable.immutable" class="ap-hint">{{ t('Immutable: set at installation.') }}</span>
						<span v-else-if="variable.secret" class="ap-hint">{{ t('Secret: leave empty to keep the stored value.') }}</span>
						<span v-if="errors[`vars.${variable.name}`]" class="ap-error" role="alert">
							<ApIcon name="x-circle" size="sm" />{{ errors[`vars.${variable.name}`] }}
						</span>
					</div>
				</div>
				<div class="ap-form-actions">
					<button type="submit" class="ap-btn ap-btn-primary"><ApIcon name="save" />{{ t('Save') }}</button>
				</div>
			</fieldset>
		</form>

		<form class="ap-stack" novalidate @submit.prevent="save('limits', { cpus: String(limits.cpus), memory: Number(limits.memory) })">
			<h3>{{ t('Limits') }}</h3>
			<fieldset class="ap-fieldset" :disabled="!canManage || busy !== ''">
				<div class="ap-form-grid">
					<div class="ap-field">
						<label class="ap-label" for="set-cpus">{{ t('CPUs') }}</label>
						<input id="set-cpus" v-model="limits.cpus" class="ap-input mono" inputmode="decimal" :aria-invalid="errors.cpus ? 'true' : 'false'" />
						<span v-if="errors.cpus" class="ap-error" role="alert"><ApIcon name="x-circle" size="sm" />{{ errors.cpus }}</span>
					</div>
					<div class="ap-field">
						<label class="ap-label" for="set-memory">{{ t('Memory (MB)') }}</label>
						<input id="set-memory" v-model="limits.memory" class="ap-input mono" type="number" min="32" :aria-invalid="errors.memory ? 'true' : 'false'" />
						<span v-if="errors.memory" class="ap-error" role="alert"><ApIcon name="x-circle" size="sm" />{{ errors.memory }}</span>
					</div>
				</div>
				<div class="ap-form-actions">
					<button type="submit" class="ap-btn ap-btn-primary"><ApIcon name="save" />{{ t('Save') }}</button>
				</div>
			</fieldset>
		</form>

		<form v-if="app.domain" class="ap-stack" novalidate @submit.prevent="domain && save('domain', { domain })">
			<h3>{{ t('Web domain') }}</h3>
			<p class="mono">{{ app.domain }}</p>
			<fieldset class="ap-fieldset" :disabled="!canManage || busy !== '' || domains.length === 0">
				<div class="ap-field">
					<label class="ap-label" for="set-domain">{{ t('Web domain') }}</label>
					<select id="set-domain" v-model="domain" class="ap-input">
						<option value="" disabled>—</option>
						<option v-for="option in domains" :key="option" :value="option">{{ option }}</option>
					</select>
					<span v-if="errors.domain" class="ap-error" role="alert"><ApIcon name="x-circle" size="sm" />{{ errors.domain }}</span>
				</div>
				<div class="ap-form-actions">
					<button type="submit" class="ap-btn ap-btn-primary" :disabled="!domain"><ApIcon name="save" />{{ t('Save') }}</button>
				</div>
			</fieldset>
		</form>
	</div>
</template>
