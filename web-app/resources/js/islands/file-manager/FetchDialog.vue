<script setup>
// Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
// §3.2): the Simple, cURL and Custom forms add a fetch into the open folder;
// below them the account's fetches with their progress, kept on the server.
import { computed, nextTick, reactive, ref, watch } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { fieldErrors } from './api.js';
import { describe, isFinished, label, percent, requestBody, tone } from './fetches.js';
import { formatSize } from './model.js';

const props = defineProps({
	open: { type: Boolean, default: false },
	/** createFetches() */
	store: { type: Object, required: true },
	/** bumped by the parent on every change of the store */
	tick: { type: Number, default: 0 },
	/** destination folder, relative to the home */
	path: { type: String, default: '' },
	/** administrators may allow private addresses */
	canPrivate: { type: Boolean, default: false },
	maxBytes: { type: Number, default: 0 },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'open-folder']);

const MODES = ['simple', 'curl', 'custom'];
const MAX_HEADERS = 50;

const mode = ref(readMode());
const form = reactive({
	url: '',
	command: '',
	headers: [{ name: '', value: '' }],
	auth: { type: 'none', username: '', password: '', token: '' },
	name: '',
	onConflict: 'rename',
	allowPrivate: false,
});
const busy = ref(false);
const errors = ref({});
const formError = ref('');
const urlInput = ref(null);
const commandInput = ref(null);

const items = computed(() => {
	props.tick;
	return [...props.store.state.items];
});
const speeds = computed(() => {
	props.tick;
	return props.store.state.speeds;
});
const finishedCount = computed(() => items.value.filter(isFinished).length);
const tabs = computed(() => [
	['simple', props.t('Simple')],
	['curl', props.t('cURL')],
	['custom', props.t('Custom')],
]);

watch(
	() => props.open,
	(open) => {
		if (open) {
			errors.value = {};
			formError.value = '';
		}
	},
);

function readMode() {
	try {
		const value = localStorage.getItem('arc.files.fetchMode');
		return MODES.includes(value) ? value : 'simple';
	} catch {
		return 'simple';
	}
}

function selectMode(name) {
	mode.value = name;
	errors.value = {};
	formError.value = '';
	try {
		localStorage.setItem('arc.files.fetchMode', name);
	} catch {
		// not remembered
	}
}

function onTabKey(event) {
	const index = MODES.indexOf(mode.value);
	const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
	if (move) {
		event.preventDefault();
		const next = MODES[(index + move + MODES.length) % MODES.length];
		selectMode(next);
		document.getElementById(`fm-fetch-tab-${next}`)?.focus();
	}
}

function addHeader() {
	if (form.headers.length < MAX_HEADERS) {
		form.headers.push({ name: '', value: '' });
	}
}

function removeHeader(index) {
	form.headers.splice(index, 1);
	if (form.headers.length === 0) {
		form.headers.push({ name: '', value: '' });
	}
}

async function submit() {
	if (busy.value) {
		return;
	}
	busy.value = true;
	errors.value = {};
	formError.value = '';
	try {
		const sent = { url: form.url, command: form.command, name: form.name };
		await props.store.start(requestBody(mode.value, form, { path: props.path, canPrivate: props.canPrivate }));
		// The next URL: the field empties and keeps the focus; custom headers
		// and authentication stay. What was typed while the request ran stays too.
		for (const key of Object.keys(sent)) {
			if (form[key] === sent[key]) {
				form[key] = '';
			}
		}
		await nextTick();
		(mode.value === 'curl' ? commandInput.value : urlInput.value)?.focus();
	} catch (error) {
		errors.value = fieldErrors(error);
		formError.value = Object.keys(errors.value).length === 0 ? (error instanceof Error ? error.message : String(error)) : '';
	} finally {
		busy.value = false;
	}
}

function error(...keys) {
	for (const key of keys) {
		if (errors.value[key]) {
			return errors.value[key];
		}
	}
	return '';
}

async function act(action, item) {
	try {
		await props.store[action](item.id);
	} catch (e) {
		formError.value = e instanceof Error ? e.message : String(e);
	}
}

async function clearFinished() {
	try {
		await props.store.clearFinished();
	} catch (e) {
		formError.value = e instanceof Error ? e.message : String(e);
	}
}

function folder(item) {
	return item.path === '' ? '~' : `~/${item.path}`;
}
</script>

<template>
	<ApDialog :open="open" :title="t('Upload from URL')" :description="t('Files go into ~/%s', path)" icon="link" wide @close="emit('close')">
		<div class="ap-stack">
			<div class="ap-tabset">
				<div class="ap-tabs" role="tablist" :aria-label="t('How to describe the download')" @keydown="onTabKey">
					<button
						v-for="[name, text] in tabs"
						:id="`fm-fetch-tab-${name}`"
						:key="name"
						type="button"
						class="ap-tab"
						role="tab"
						:aria-selected="mode === name ? 'true' : 'false'"
						aria-controls="fm-fetch-form"
						:tabindex="mode === name ? 0 : -1"
						@click="selectMode(name)"
					>
						{{ text }}
					</button>
				</div>
			</div>

			<form id="fm-fetch-form" class="ap-stack" role="tabpanel" :aria-labelledby="`fm-fetch-tab-${mode}`" novalidate @submit.prevent="submit">
				<div v-if="mode !== 'curl'" class="ap-field">
					<label class="ap-label" for="fm-fetch-url">{{ t('URL') }}</label>
					<input
						id="fm-fetch-url"
						ref="urlInput"
						v-model="form.url"
						class="ap-input ap-input-mono"
						type="url"
						inputmode="url"
						autocomplete="off"
						spellcheck="false"
						placeholder="https://example.com/file.zip"
						data-autofocus
						:aria-invalid="error('url') ? 'true' : undefined"
						aria-describedby="fm-fetch-url-error"
					/>
					<p v-if="error('url')" id="fm-fetch-url-error" class="ap-error">{{ error('url') }}</p>
				</div>

				<div v-else class="ap-field">
					<label class="ap-label" for="fm-fetch-command">{{ t('Paste one curl command') }}</label>
					<textarea
						id="fm-fetch-command"
						ref="commandInput"
						v-model="form.command"
						class="ap-textarea ap-input-mono"
						rows="5"
						spellcheck="false"
						autocomplete="off"
						placeholder="curl 'https://example.com/file.zip' -H 'Authorization: Bearer …'"
						data-autofocus
						:aria-invalid="error('command') ? 'true' : undefined"
						aria-describedby="fm-fetch-command-hint"
					></textarea>
					<p v-if="error('command')" id="fm-fetch-command-hint" class="ap-error">{{ error('command') }}</p>
					<p v-else id="fm-fetch-command-hint" class="ap-hint">{{ t('The command is read, never run: only the URL, headers and credentials are used.') }}</p>
				</div>

				<template v-if="mode === 'custom'">
					<fieldset class="ap-fieldset-plain ap-stack">
						<legend class="ap-label">{{ t('HTTP headers') }}</legend>
						<div v-for="(header, index) in form.headers" :key="index" class="ap-fetch-header">
							<input
								v-model="header.name"
								class="ap-input ap-input-mono"
								type="text"
								autocomplete="off"
								spellcheck="false"
								:placeholder="t('Name')"
								:aria-label="t('Header name %d', index + 1)"
								:aria-invalid="error(`headers.${index}.name`) ? 'true' : undefined"
							/>
							<input
								v-model="header.value"
								class="ap-input ap-input-mono"
								type="text"
								autocomplete="off"
								spellcheck="false"
								:placeholder="t('Value')"
								:aria-label="t('Header value %d', index + 1)"
								:aria-invalid="error(`headers.${index}.value`) ? 'true' : undefined"
							/>
							<button type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Remove header %d', index + 1)" @click="removeHeader(index)">
								<ApIcon name="x" />
							</button>
							<p v-if="error(`headers.${index}.name`, `headers.${index}.value`)" class="ap-error">{{ error(`headers.${index}.name`, `headers.${index}.value`) }}</p>
						</div>
						<p v-if="error('headers')" class="ap-error">{{ error('headers') }}</p>
						<div>
							<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :disabled="form.headers.length >= 50" @click="addHeader"><ApIcon name="plus" />{{ t('Add header') }}</button>
						</div>
					</fieldset>

					<div class="ap-form-grid">
						<div class="ap-field">
							<label class="ap-label" for="fm-fetch-auth">{{ t('Authentication') }}</label>
							<select id="fm-fetch-auth" v-model="form.auth.type" class="ap-select">
								<option value="none">{{ t('None') }}</option>
								<option value="basic">{{ t('Basic (user name and password)') }}</option>
								<option value="bearer">{{ t('Bearer token') }}</option>
							</select>
						</div>
						<template v-if="form.auth.type === 'basic'">
							<div class="ap-field">
								<label class="ap-label" for="fm-fetch-user">{{ t('User name') }}</label>
								<input id="fm-fetch-user" v-model="form.auth.username" class="ap-input" type="text" autocomplete="off" :aria-invalid="error('auth.username') ? 'true' : undefined" />
								<p v-if="error('auth.username')" class="ap-error">{{ error('auth.username') }}</p>
							</div>
							<div class="ap-field">
								<label class="ap-label" for="fm-fetch-password">{{ t('Password') }}</label>
								<input id="fm-fetch-password" v-model="form.auth.password" class="ap-input" type="password" autocomplete="new-password" :aria-invalid="error('auth.password') ? 'true' : undefined" />
								<p v-if="error('auth.password')" class="ap-error">{{ error('auth.password') }}</p>
							</div>
						</template>
						<div v-else-if="form.auth.type === 'bearer'" class="ap-field">
							<label class="ap-label" for="fm-fetch-token">{{ t('Token') }}</label>
							<input id="fm-fetch-token" v-model="form.auth.token" class="ap-input ap-input-mono" type="password" autocomplete="off" :aria-invalid="error('auth.token') ? 'true' : undefined" />
							<p v-if="error('auth.token')" class="ap-error">{{ error('auth.token') }}</p>
						</div>
					</div>
				</template>

				<div class="ap-form-grid">
					<div class="ap-field">
						<label class="ap-label" for="fm-fetch-name">{{ t('File name (optional)') }}</label>
						<input
							id="fm-fetch-name"
							v-model="form.name"
							class="ap-input ap-input-mono"
							type="text"
							autocomplete="off"
							spellcheck="false"
							maxlength="255"
							:placeholder="t('The name the server sends')"
							:aria-invalid="error('name') ? 'true' : undefined"
						/>
						<p v-if="error('name')" class="ap-error">{{ error('name') }}</p>
					</div>
					<div class="ap-field">
						<label class="ap-label" for="fm-fetch-conflict">{{ t('If the name exists') }}</label>
						<select id="fm-fetch-conflict" v-model="form.onConflict" class="ap-select">
							<option value="rename">{{ t('Keep both (name_(1).ext)') }}</option>
							<option value="overwrite">{{ t('Overwrite') }}</option>
							<option value="skip">{{ t('Skip') }}</option>
						</select>
					</div>
				</div>

				<div v-if="canPrivate" class="ap-field">
					<label class="ap-check"><input v-model="form.allowPrivate" type="checkbox" /><span>{{ t('Allow private addresses') }}</span></label>
					<p class="ap-hint">{{ t('Lets the server reach its own network and local services. Only administrators can choose this.') }}</p>
					<p v-if="error('allowPrivate')" class="ap-error">{{ error('allowPrivate') }}</p>
				</div>

				<p v-if="formError" class="ap-error" role="alert">{{ formError }}</p>
				<p v-if="maxBytes > 0" class="ap-hint">{{ t('Files up to %s; up to 3 downloads run at once.', formatSize(maxBytes)) }}</p>

				<div class="ap-actions">
					<button type="submit" class="ap-btn ap-btn-primary" :disabled="busy"><ApIcon name="plus" />{{ t('Add') }}</button>
				</div>
			</form>

			<section class="ap-card ap-card-flush" :aria-label="t('Downloads')">
				<div class="ap-card-head">
					<div class="ap-card-title">
						<ApIcon name="download" />
						<h3>{{ t('Downloads') }}</h3>
					</div>
					<div class="ap-actions">
						<button v-if="finishedCount > 0" type="button" class="ap-btn ap-btn-ghost ap-btn-sm" @click="clearFinished"><ApIcon name="check" />{{ t('Clear finished') }}</button>
					</div>
				</div>
				<p v-if="items.length === 0" class="ap-fetch-empty muted">{{ t('Files you add from a URL appear here.') }}</p>
				<ul v-else class="ap-upload-list" aria-live="polite">
					<li v-for="item in items" :key="item.id">
						<div class="ap-upload-row">
							<div class="ap-cell-main">
								<b :title="item.url">{{ item.name || item.url }}</b>
								<span>{{ folder(item) }} · {{ describe(item, t, formatSize, speeds[item.id]) }}</span>
							</div>
							<ApBadge :tone="tone(item)">{{ label(item, t) }}</ApBadge>
							<button
								v-if="item.status === 'done' && item.path !== path"
								type="button"
								class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm"
								:aria-label="t('Open the folder of %s', item.name)"
								@click="emit('open-folder', item.path)"
							>
								<ApIcon name="folder" />
							</button>
							<button
								v-if="!isFinished(item)"
								type="button"
								class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm"
								:aria-label="t('Cancel %s', item.name || item.url)"
								@click="act('cancel', item)"
							>
								<ApIcon name="x" />
							</button>
							<button v-else type="button" class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" :aria-label="t('Remove %s from the list', item.name || item.url)" @click="act('cancel', item)">
								<ApIcon name="trash" />
							</button>
						</div>
						<span
							v-if="item.status === 'running'"
							:class="['ap-bar', percent(item) === null ? 'is-indeterminate' : '']"
							role="progressbar"
							:aria-label="t('Progress of %s', item.name || item.url)"
							aria-valuemin="0"
							aria-valuemax="100"
							:aria-valuenow="percent(item) ?? undefined"
						>
							<span :style="{ width: `${percent(item) ?? 0}%` }"></span>
						</span>
					</li>
				</ul>
			</section>
		</div>
		<template #footer>
			<button type="button" class="ap-btn ap-btn-ghost" @click="emit('close')">{{ t('Close') }}</button>
		</template>
	</ApDialog>
</template>
