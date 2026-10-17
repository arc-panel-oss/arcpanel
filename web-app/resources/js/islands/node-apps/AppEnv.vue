<script setup>
// Environment of an app (E14 spec D13): KEY=value rows of .env, values
// masked until revealed, saved as a whole; the app restarts to read them.
// Values may reference the secret store as {{secret:NAME}} (E38): they are
// shown as written, and a picker adds a row with such a reference.
import { computed, onMounted, ref } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { fieldErrors } from './api.js';
import { envChanged, envErrors, isSecretReference, RESERVED_ENV, secretReference } from './model.js';

const props = defineProps({
	api: { type: Object, required: true },
	domain: { type: String, required: true },
	canManage: { type: Boolean, default: false },
	secrets: { type: Array, default: () => [] },
	t: { type: Function, required: true },
	// App processes (E42) reuse the editor with their own path, keys and texts
	path: { type: String, default: '' },
	reserved: { type: Array, default: () => RESERVED_ENV },
	hint: { type: String, default: '' },
	savedMessage: { type: String, default: '' },
});

const t = props.t;
const saved = ref([]);
const rows = ref([]);
const revealed = ref(new Set());
const loading = ref(true);
const busy = ref(false);
const serverErrors = ref({});
const picked = ref('');

const errors = computed(() => envErrors(rows.value, props.reserved));
const changed = computed(() => envChanged(saved.value, rows.value));
const path = () => props.path || `/${encodeURIComponent(props.domain)}/env`;

function load(vars) {
	saved.value = vars.map((row) => ({ ...row }));
	rows.value = vars.map((row) => ({ ...row }));
	revealed.value = new Set();
}

function add() {
	rows.value.push({ key: '', value: '' });
	revealed.value.add(rows.value.length - 1);
}

function addSecret() {
	if (picked.value === '') {
		return;
	}
	rows.value.push({ key: '', value: secretReference(picked.value) });
	picked.value = '';
}

function remove(index) {
	rows.value.splice(index, 1);
	revealed.value = new Set([...revealed.value].filter((i) => i !== index).map((i) => (i > index ? i - 1 : i)));
}

function toggle(index) {
	const next = new Set(revealed.value);
	if (next.has(index)) {
		next.delete(index);
	} else {
		next.add(index);
	}
	revealed.value = next;
}

async function save() {
	if (Object.keys(errors.value).length > 0) {
		return;
	}
	busy.value = true;
	serverErrors.value = {};
	try {
		const data = await props.api.call('PUT', path(), { vars: rows.value.map((row) => ({ key: row.key.trim(), value: row.value })) });
		load(data.vars);
		props.api.saved(props.savedMessage || t('Saved; the app was restarted.'));
	} catch (error) {
		serverErrors.value = fieldErrors(error);
		props.api.fail(error);
	} finally {
		busy.value = false;
	}
}

onMounted(async () => {
	try {
		load((await props.api.call('GET', path())).vars);
	} catch (error) {
		props.api.fail(error);
	} finally {
		loading.value = false;
	}
});
</script>

<template>
	<form class="ap-stack" novalidate @submit.prevent="save">
		<p class="muted">{{ hint || t('Variables of the .env file of the app. PORT, HOST and NODE_ENV are set by the panel.') }}</p>
		<p class="muted">{{ t('A value can reference a secret of the account as %s; the app gets its value when it starts.', secretReference('NAME')) }}</p>
		<p v-if="loading" class="muted">{{ t('Loading…') }}</p>
		<div v-else class="ap-table-wrap">
			<table class="ap-table">
				<caption class="sr-only">{{ t('Environment') }}</caption>
				<thead>
					<tr>
						<th scope="col">{{ t('Key') }}</th>
						<th scope="col">{{ t('Value') }}</th>
						<th scope="col" class="col-actions"><span class="sr-only">{{ t('Actions') }}</span></th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="rows.length === 0">
						<td colspan="3" class="muted">{{ t('No variable yet.') }}</td>
					</tr>
					<tr v-for="(row, index) in rows" :key="index">
						<td>
							<div class="ap-field" :class="{ 'is-invalid': errors[index] || serverErrors[`vars.${index}.key`] }">
								<label class="sr-only" :for="`env-key-${index}`">{{ t('Key') }}</label>
								<input :id="`env-key-${index}`" v-model="row.key" class="ap-input ap-input-mono" type="text" spellcheck="false" autocomplete="off" :disabled="!canManage" />
								<span v-if="errors[index]" class="ap-error">{{ t(errors[index], row.key) }}</span>
								<span v-else-if="serverErrors[`vars.${index}.key`]" class="ap-error">{{ serverErrors[`vars.${index}.key`] }}</span>
							</div>
						</td>
						<td>
							<label class="sr-only" :for="`env-value-${index}`">{{ t('Value of %s', row.key) }}</label>
							<input
								:id="`env-value-${index}`"
								v-model="row.value"
								class="ap-input ap-input-mono"
								:type="revealed.has(index) || isSecretReference(row.value) ? 'text' : 'password'"
								spellcheck="false"
								autocomplete="off"
								:disabled="!canManage"
							/>
						</td>
						<td class="col-actions">
							<div class="ap-row ap-row-end">
								<button
									type="button"
									class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
									:aria-label="revealed.has(index) ? t('Hide %s', row.key) : t('Show %s', row.key)"
									:aria-pressed="revealed.has(index) ? 'true' : 'false'"
									@click="toggle(index)"
								>
									<ApIcon name="eye" />
								</button>
								<button
									v-if="canManage"
									type="button"
									class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
									:aria-label="t('Remove %s', row.key)"
									@click="remove(index)"
								>
									<ApIcon name="trash" />
								</button>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<div v-if="canManage" class="ap-actions">
			<button type="button" class="ap-btn ap-btn-sm" :disabled="loading || rows.length >= 200" @click="add"><ApIcon name="plus" />{{ t('Add a variable') }}</button>
			<template v-if="secrets.length > 0">
				<label class="sr-only" for="env-secret-pick">{{ t('Insert a secret') }}</label>
				<select id="env-secret-pick" v-model="picked" class="ap-select" :disabled="loading || rows.length >= 200" @change="addSecret">
					<option value="">{{ t('Insert a secret') }}</option>
					<option v-for="name in secrets" :key="name" :value="name">{{ name }}</option>
				</select>
			</template>
			<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy || !changed || Object.keys(errors).length > 0">
				<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="save" />{{ t('Save and restart') }}
			</button>
		</div>
	</form>
</template>
