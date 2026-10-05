<script setup>
// Node.js runtimes and global packages of one account (E13 spec §7.2).
// Every change goes through the JSON routes of NodeController; installs,
// updates and the outdated check run as a background task that is polled.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { request } from '../../http.js';
import { toast } from '../../toast.js';
import ApBadge from '../shared/ApBadge.vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createTaskPoller } from '../shared/task-poller.js';
import { isPackageName, isPackageSpec, quickPackages, releaseStatus, taskLabel } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const base = props.initial.base;
const readOnly = Boolean(props.initial.readOnly);
const terminal = props.initial.terminal ?? null;

const account = ref(props.initial.account);
const task = ref(props.initial.task ?? {});
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const busy = ref(false);
const showLog = ref(Boolean(task.value.running));

const canManage = computed(() => abilities.value.manage && account.value.allowed && !account.value.suspended);
const canRemove = computed(() => abilities.value.remove);
const taskRunning = computed(() => Boolean(task.value.running));
const runtimes = computed(() => account.value.runtimes ?? []);
const limitLabel = computed(() => (account.value.limit === 'unlimited' ? t('unlimited') : account.value.limit));

// Global packages of the selected runtime
const selected = ref(pickRuntime(''));
const packages = ref({ checked: '', packages: [] });
const packagesLoading = ref(false);
const spec = ref('');
const specError = ref('');
const quick = computed(() => quickPackages(packages.value.packages));

// Install dialog
const installOpen = ref(false);
const releases = ref([]);
const releasesLoading = ref(false);
const releaseChoice = ref('');

// Confirmation
const confirmation = reactive({ open: false, message: '', run: null });

/** The runtime to keep selected: the current one while it exists, else the default. */
function pickRuntime(current) {
	const list = account.value.runtimes ?? [];
	if (list.some((runtime) => runtime.version === current)) {
		return current;
	}
	return account.value.default || list.at(-1)?.version || '';
}

function call(method, path, body) {
	return request(`${base}${path}`, { method, body });
}

function fail(error) {
	toast('error', t('Something went wrong'), error instanceof Error ? error.message : String(error));
}

function apply(data) {
	if (data?.account) {
		account.value = data.account;
	}
	if (data?.task) {
		task.value = data.task;
	}
	if (data?.abilities) {
		abilities.value = data.abilities;
	}
	selected.value = pickRuntime(selected.value);
}

async function refresh() {
	apply(await call('GET', '/overview'));
}

async function act(method, path, body) {
	busy.value = true;
	try {
		apply(await call(method, path, body));
		toast('ok', t('Changes have been saved.'));
	} catch (error) {
		fail(error);
	} finally {
		busy.value = false;
	}
}

async function loadPackages() {
	if (!selected.value) {
		packages.value = { checked: '', packages: [] };
		return;
	}
	packagesLoading.value = true;
	try {
		packages.value = await call('GET', `/versions/${selected.value}/packages`);
	} catch (error) {
		fail(error);
	} finally {
		packagesLoading.value = false;
	}
}

const poller = createTaskPoller({
	fetchTask: async () => (await call('GET', '/task')).task,
	onUpdate: (value) => {
		task.value = value;
	},
	onFinish: async (value) => {
		const ok = value.status === 'done';
		toast(ok ? 'ok' : 'error', taskLabel(value, t), ok ? t('Done') : t('Failed'));
		try {
			await refresh();
			await loadPackages();
		} catch (error) {
			fail(error);
		}
	},
});

async function startTask(path, body) {
	busy.value = true;
	try {
		const data = await call('POST', path, body);
		task.value = data.task;
		showLog.value = true;
		poller.start();
		return true;
	} catch (error) {
		fail(error);
		return false;
	} finally {
		busy.value = false;
	}
}

function confirmThen(message, run) {
	confirmation.message = message;
	confirmation.run = run;
	confirmation.open = true;
}

async function confirmAccept() {
	const run = confirmation.run;
	confirmation.open = false;
	confirmation.run = null;
	await run?.();
}

// Runtimes
function enable() {
	act('POST', '/enable');
}

function disable() {
	confirmThen(t('Remove Node.js and every runtime and package? This cannot be undone.'), () => act('DELETE', '/enable'));
}

function makeDefault(version) {
	act('POST', `/versions/${version}/default`);
}

function uninstall(version) {
	confirmThen(t('Uninstall Node.js %s and its global packages?', version), () => act('DELETE', `/versions/${version}`));
}

async function openInstall() {
	installOpen.value = true;
	releaseChoice.value = '';
	if (releases.value.length > 0) {
		return;
	}
	releasesLoading.value = true;
	try {
		releases.value = (await call('GET', '/releases')).releases;
	} catch (error) {
		fail(error);
	} finally {
		releasesLoading.value = false;
	}
}

function installed(release) {
	return runtimes.value.some((runtime) => runtime.version === release.latest);
}

async function install() {
	if (releaseChoice.value === '') {
		return;
	}
	if (await startTask('/versions', { spec: releaseChoice.value })) {
		installOpen.value = false;
	}
}

// Packages
async function addPackage(value = spec.value.trim()) {
	specError.value = '';
	if (!isPackageSpec(value)) {
		specError.value = t('e.g. pm2 or @nestjs/cli@11');
		return;
	}
	if (await startTask(`/versions/${selected.value}/packages`, { spec: value })) {
		spec.value = '';
	}
}

function updateAll() {
	startTask(`/versions/${selected.value}/packages/update`);
}

function checkUpdates() {
	startTask(`/versions/${selected.value}/packages/outdated`);
}

function removePackage(name) {
	if (!isPackageName(name)) {
		return;
	}
	const version = selected.value;
	confirmThen(t('Remove %s?', name), async () => {
		busy.value = true;
		try {
			packages.value = await call('DELETE', `/versions/${version}/packages/${name}`);
			await refresh();
		} catch (error) {
			fail(error);
		} finally {
			busy.value = false;
		}
	});
}

watch(selected, loadPackages);

onMounted(() => {
	loadPackages();
	if (taskRunning.value) {
		poller.start();
	}
});

onBeforeUnmount(() => poller.stop());
</script>

<template>
	<div class="ap-stack">
		<div v-if="readOnly" class="ap-alert is-info" role="note">
			<ApIcon name="info" />
			<div class="ap-alert-body"><p>{{ t('Read-only: changes are disabled.') }}</p></div>
		</div>
		<div v-if="!account.allowed" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ t('Node.js is not included in your package.') }}</p></div>
		</div>

		<section class="ap-card" aria-labelledby="node-summary-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="hexagon" />
					<h2 id="node-summary-title">{{ t('Node.js') }}</h2>
				</div>
				<div class="ap-actions">
					<a v-if="terminal && account.enabled" class="ap-btn ap-btn-ghost ap-btn-sm" :href="terminal">
						<ApIcon name="terminal" />{{ t('Open terminal') }}
					</a>
					<button
						v-if="account.enabled && canRemove"
						type="button"
						class="ap-btn ap-btn-sm ap-btn-danger"
						:disabled="busy || taskRunning"
						@click="disable"
					>
						<ApIcon name="trash" />{{ t('Remove Node.js') }}
					</button>
				</div>
			</div>
			<div v-if="!account.enabled" class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="hexagon" /></div>
				<h3>{{ t('Node.js is not enabled yet.') }}</h3>
				<p>{{ t('Enable Node.js to install runtimes and global packages. Your shell (SSH, Web Terminal) then has node, npm and nvm.') }}</p>
				<div v-if="canManage" class="ap-actions">
					<button type="button" class="ap-btn ap-btn-primary" :disabled="busy" @click="enable">
						<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="play" />{{ t('Enable Node.js') }}
					</button>
				</div>
			</div>
			<dl v-else class="ap-dl">
				<dt>{{ t('Runtimes') }}</dt>
				<dd class="mono">{{ t('%d of %s runtimes', runtimes.length, limitLabel) }}</dd>
				<dt>{{ t('Default') }}</dt>
				<dd class="mono">{{ account.default ? `v${account.default}` : '—' }}</dd>
				<dt>{{ t('LTS') }}</dt>
				<dd class="mono">{{ t('Allowed majors: %s', account.allowedMajors) }}</dd>
			</dl>
		</section>

		<section v-if="account.enabled" class="ap-card ap-card-flush" aria-labelledby="node-runtimes-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="layers" />
					<h2 id="node-runtimes-title">{{ t('Runtimes') }}</h2>
				</div>
				<div v-if="canManage" class="ap-actions">
					<button
						type="button"
						class="ap-btn ap-btn-primary ap-btn-sm"
						:disabled="busy || taskRunning || !account.hasRoom"
						:title="account.hasRoom ? '' : t('Your package allows no more runtimes.')"
						@click="openInstall"
					>
						<ApIcon name="plus" />{{ t('Install a version') }}
					</button>
				</div>
			</div>
			<div v-if="runtimes.length === 0" class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="hexagon" /></div>
				<h3>{{ t('No runtime is installed yet.') }}</h3>
			</div>
			<div v-else class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('Runtimes') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('Version') }}</th>
							<th scope="col">{{ t('Status') }}</th>
							<th scope="col" class="col-num">{{ t('Size') }}</th>
							<th scope="col" class="col-num">{{ t('Packages') }}</th>
							<th scope="col" class="col-actions"><span class="sr-only">{{ t('Runtime') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="runtime in runtimes" :key="runtime.version">
							<td :data-label="t('Version')">
								<span class="mono">v{{ runtime.version }}</span>
								<span v-if="runtime.lts" class="muted"> · {{ runtime.lts }}</span>
								<ApBadge v-if="runtime.default" tone="accent">{{ t('Default') }}</ApBadge>
							</td>
							<td :data-label="t('Status')">
								<ApBadge v-if="releaseStatus(runtime.status).label" :tone="releaseStatus(runtime.status).tone">
									{{ t(releaseStatus(runtime.status).label) }}
								</ApBadge>
								<span v-if="runtime.status === 'eol' && runtime.eol" class="muted"> {{ t('End of life on %s', runtime.eol) }}</span>
							</td>
							<td :data-label="t('Size')" class="col-num mono">{{ runtime.sizeMb }} {{ t('MB') }}</td>
							<td :data-label="t('Packages')" class="col-num mono">{{ runtime.packages }}</td>
							<td class="col-actions">
								<div class="ap-row ap-row-end">
									<button
										v-if="canManage && !runtime.default"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm"
										:disabled="busy"
										@click="makeDefault(runtime.version)"
									>
										{{ t('Make default') }}
									</button>
									<button
										v-if="canRemove"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Uninstall')} v${runtime.version}`"
										:title="t('Uninstall')"
										:disabled="busy || taskRunning"
										@click="uninstall(runtime.version)"
									>
										<ApIcon name="trash" />
									</button>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="ap-card-foot">
				<span>{{ t('The default runtime is used by your shell and by new apps.') }}</span>
				<span v-if="canManage && !account.hasRoom">{{ t('Your package allows no more runtimes.') }}</span>
			</div>
		</section>

		<section v-if="account.enabled && runtimes.length > 0" class="ap-card ap-card-flush" aria-labelledby="node-packages-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="package" />
					<h2 id="node-packages-title">{{ t('Global packages') }}</h2>
				</div>
				<div class="ap-actions">
					<label class="sr-only" for="node-runtime">{{ t('Select a runtime') }}</label>
					<div class="ap-select-wrap">
						<select id="node-runtime" v-model="selected" class="ap-select">
							<option v-for="runtime in runtimes" :key="runtime.version" :value="runtime.version">v{{ runtime.version }}</option>
						</select>
						<ApIcon name="chevron-down" size="sm" />
					</div>
					<template v-if="canManage">
						<button type="button" class="ap-btn ap-btn-sm" :disabled="busy || taskRunning" @click="checkUpdates">
							<ApIcon name="search" />{{ t('Check for updates') }}
						</button>
						<button type="button" class="ap-btn ap-btn-sm" :disabled="busy || taskRunning" @click="updateAll">
							<ApIcon name="refresh" />{{ t('Update all') }}
						</button>
					</template>
				</div>
			</div>
			<form v-if="canManage" class="ap-toolbar" novalidate @submit.prevent="addPackage()">
				<div class="ap-field ap-toolbar-search" :class="{ 'is-invalid': specError }">
					<label class="sr-only" for="node-package">{{ t('Package name') }}</label>
					<input
						id="node-package"
						v-model="spec"
						class="ap-input ap-input-mono"
						type="text"
						autocomplete="off"
						spellcheck="false"
						:placeholder="t('e.g. pm2 or @nestjs/cli@11')"
						:aria-invalid="specError ? 'true' : undefined"
						:aria-describedby="specError ? 'node-package-error' : undefined"
					/>
					<span v-if="specError" id="node-package-error" class="ap-error"><ApIcon name="x-circle" size="sm" />{{ specError }}</span>
				</div>
				<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy || taskRunning || spec.trim() === ''">
					<ApIcon name="plus" />{{ t('Add') }}
				</button>
				<span v-if="quick.length > 0" class="muted">{{ t('Quick add') }}</span>
				<button
					v-for="name in quick"
					:key="name"
					type="button"
					class="ap-btn ap-btn-ghost ap-btn-sm"
					:disabled="busy || taskRunning"
					@click="addPackage(name)"
				>
					<span class="mono">{{ name }}</span>
				</button>
			</form>
			<div class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('Global packages') }} v{{ selected }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('Package') }}</th>
							<th scope="col">{{ t('Version') }}</th>
							<th scope="col">{{ t('Latest') }}</th>
							<th scope="col" class="col-actions"><span class="sr-only">{{ t('Package') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-if="packagesLoading && packages.packages.length === 0">
							<td colspan="4" class="muted">{{ t('Loading…') }}</td>
						</tr>
						<tr v-for="item in packages.packages" :key="item.name">
							<td :data-label="t('Package')">
								<span class="mono">{{ item.name }}</span>
								<span v-if="item.bundled" class="muted"> · {{ t('Comes with Node.js') }}</span>
							</td>
							<td :data-label="t('Version')" class="mono">{{ item.version }}</td>
							<td :data-label="t('Latest')">
								<ApBadge v-if="item.outdated === 'yes'" tone="warn">{{ t('Update available') }} {{ item.latest }}</ApBadge>
								<ApBadge v-else-if="item.outdated === 'no'" tone="ok">{{ t('Up to date') }}</ApBadge>
								<span v-else class="muted">—</span>
							</td>
							<td class="col-actions">
								<div class="ap-row ap-row-end">
									<button
										v-if="canRemove && !item.bundled"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Remove')} ${item.name}`"
										:title="t('Remove')"
										:disabled="busy || taskRunning"
										@click="removePackage(item.name)"
									>
										<ApIcon name="trash" />
									</button>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="ap-card-foot">
				<span>{{ packages.checked ? t('Last checked: %s', packages.checked) : t('Not checked yet') }}</span>
			</div>
		</section>

		<section v-if="task.id" class="ap-card" aria-labelledby="node-task-title" aria-live="polite">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="activity" />
					<h2 id="node-task-title">{{ taskLabel(task, t) }}</h2>
				</div>
				<div class="ap-actions">
					<ApBadge :tone="task.running ? 'info' : task.status === 'done' ? 'ok' : 'danger'">
						<span v-if="task.running" class="ap-spinner" aria-hidden="true" />
						{{ task.running ? t('Running') : task.status === 'done' ? t('Done') : t('Failed') }}
					</ApBadge>
					<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :aria-expanded="showLog ? 'true' : 'false'" @click="showLog = !showLog">
						{{ showLog ? t('Hide the output') : t('Show the output') }}
					</button>
				</div>
			</div>
			<p class="ap-hint mono">{{ task.start }}<template v-if="task.end"> → {{ task.end }}</template></p>
			<div v-if="showLog" class="ap-log">
				<div class="ap-log-body" tabindex="0" role="log" :aria-label="taskLabel(task, t)">
					<div v-for="(line, index) in (task.log || '').split('\n')" :key="index" class="ap-log-line">{{ line }}</div>
				</div>
			</div>
		</section>

		<ApDialog :open="installOpen" :title="t('Install a version')" icon="hexagon" @close="installOpen = false">
			<p v-if="releasesLoading" class="muted">{{ t('Loading…') }}</p>
			<fieldset v-else class="ap-field ap-fieldset">
				<legend class="ap-label">{{ t('Version') }}</legend>
				<div class="ap-stack">
					<label v-for="release in releases" :key="release.major" class="ap-check">
						<input
							v-model="releaseChoice"
							type="radio"
							name="node-release"
							:value="release.major"
							:disabled="!release.allowed || installed(release)"
						/>
						<span>
							<b class="mono">Node.js {{ release.major }}</b>
							<span class="mono muted"> v{{ release.latest }}</span>
							<span v-if="release.lts" class="muted"> · {{ release.lts }}</span>
							<ApBadge v-if="releaseStatus(release.status).label" :tone="releaseStatus(release.status).tone">
								{{ t(releaseStatus(release.status).label) }}
							</ApBadge>
							<span v-if="installed(release)" class="muted"> · {{ t('Installed') }}</span>
							<span v-else-if="!release.allowed" class="muted"> · {{ t('Not allowed by your package') }}</span>
							<span v-if="release.status === 'eol' && release.eol" class="muted"> · {{ t('End of life on %s', release.eol) }}</span>
						</span>
					</label>
				</div>
			</fieldset>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" @click="installOpen = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-primary" :disabled="busy || releaseChoice === ''" @click="install">
					<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="download" />{{ t('Install') }}
				</button>
			</template>
		</ApDialog>

		<ApDialog :open="confirmation.open" :title="t('Remove')" icon="alert" @close="confirmation.open = false">
			<p>{{ confirmation.message }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="confirmation.open = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" @click="confirmAccept">{{ t('Remove') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
