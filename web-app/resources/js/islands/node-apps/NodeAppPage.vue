<script setup>
// Page of one Node.js app (E14 spec §7): state and actions, then the tabs
// Overview (with install and build), Logs, Environment, Settings and
// Danger zone. The state is read again every 5 seconds.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createTaskPoller } from '../shared/task-poller.js';
import AppEnv from './AppEnv.vue';
import AppLogs from './AppLogs.vue';
import AppSettings from './AppSettings.vue';
import { createApi } from './api.js';
import { appStatus, formatUptime } from './model.js';
import TaskPanel from './TaskPanel.vue';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const app = ref(props.initial.app);
const task = ref(props.initial.task ?? {});
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const scripts = ref([]);
const busy = ref(false);
const deleting = ref({ open: false, keepFiles: false });

const tabs = [
	['overview', t('Overview')],
	['logs', t('Logs')],
	['env', t('Environment')],
	['settings', t('Settings')],
	['danger', t('Danger zone')],
];
const initialTab = tabs.some(([name]) => `#${name}` === window.location.hash) ? window.location.hash.slice(1) : 'overview';
const tab = ref(initialTab);

const canManage = computed(() => abilities.value.manage && !app.value.suspended);
const canRemove = computed(() => abilities.value.remove);
const status = computed(() => appStatus(app.value));
const path = computed(() => `/${encodeURIComponent(app.value.domain)}`);
// File manager (E3): the working directory of the app
const folderUrl = computed(() => `${pages.files}?path=${['web', app.value.domain, ...String(app.value.cwd).split('/').filter((p) => p !== '' && p !== '.')].map(encodeURIComponent).join('/')}`);

function apply(data) {
	if (data?.app) {
		app.value = data.app;
	}
	if (data?.task) {
		task.value = data.task;
	}
	if (data?.abilities) {
		abilities.value = data.abilities;
	}
}

async function refresh() {
	try {
		apply(await api.call('GET', path.value));
	} catch {
		// tried again on the next round
	}
}

async function act(action) {
	busy.value = true;
	try {
		apply(await api.call('POST', `${path.value}/${action}`));
		api.saved();
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

const poller = createTaskPoller({
	fetchTask: async () => (await api.call('GET', path.value)).task,
	onUpdate: (value) => {
		task.value = value;
	},
	onFinish: async (value) => {
		api.toast(value.status === 'done' ? 'ok' : 'error', value.status === 'done' ? t('Done') : t('Failed'));
		await refresh();
	},
	onError: () => {},
});

async function startTask(part, body) {
	busy.value = true;
	try {
		const data = await api.call('POST', `${path.value}/${part}`, body);
		task.value = data.task;
		poller.start();
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

async function destroy() {
	busy.value = true;
	try {
		await api.call('DELETE', path.value, deleting.value.keepFiles ? { keep_files: true } : undefined);
		window.location.assign(pages.list);
	} catch (error) {
		api.fail(error);
		busy.value = false;
	}
	deleting.value.open = false;
}

function selectTab(name) {
	tab.value = name;
	history.replaceState(null, '', `#${name}`);
}

function onTabKey(event) {
	const index = tabs.findIndex(([name]) => name === tab.value);
	const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
	if (move) {
		event.preventDefault();
		const next = tabs[(index + move + tabs.length) % tabs.length][0];
		selectTab(next);
		document.getElementById(`app-tab-${next}`)?.focus();
	}
}

let timer = null;
function schedule() {
	timer = setTimeout(async () => {
		if (document.visibilityState === 'visible' && !busy.value) {
			await refresh();
		}
		schedule();
	}, 5000);
}

onMounted(async () => {
	schedule();
	if (task.value.running) {
		poller.start();
	}
	try {
		scripts.value = (await api.call('GET', `/detect/${encodeURIComponent(app.value.domain)}?cwd=${encodeURIComponent(app.value.cwd)}`)).scripts;
	} catch {
		scripts.value = [];
	}
});

onBeforeUnmount(() => {
	clearTimeout(timer);
	poller.stop();
});
</script>

<template>
	<div class="ap-stack">
		<div v-if="initial.readOnly" class="ap-alert is-info" role="note">
			<ApIcon name="info" />
			<div class="ap-alert-body"><p>{{ t('Read-only: changes are disabled.') }}</p></div>
		</div>
		<div v-if="app.suspended" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ t('This app is suspended.') }}</p></div>
		</div>

		<section class="ap-card" aria-labelledby="app-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="hexagon" />
					<h2 id="app-title" class="mono">{{ app.domain }}</h2>
					<ApBadge :tone="status.tone">{{ t(status.label) }}</ApBadge>
				</div>
				<div class="ap-actions">
					<a v-if="pages.files" class="ap-btn ap-btn-ghost ap-btn-sm" :href="folderUrl"><ApIcon name="folder" />{{ t('Open app folder') }}</a>
					<a class="ap-btn ap-btn-ghost ap-btn-sm" :href="app.url" target="_blank" rel="noopener">
						<ApIcon name="external" />{{ app.url }}
						<ApBadge v-if="app.ssl" tone="ok">HTTPS</ApBadge>
					</a>
					<button v-if="!app.online && canManage" type="button" class="ap-btn ap-btn-sm ap-btn-primary" :disabled="busy" @click="act('start')">
						<ApIcon name="play" />{{ t('Start') }}
					</button>
					<template v-if="app.online">
						<button v-if="canManage" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('restart')"><ApIcon name="rotate" />{{ t('Restart') }}</button>
						<button v-if="canManage" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('reload')"><ApIcon name="refresh" />{{ t('Reload') }}</button>
						<button v-if="canRemove" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('stop')"><ApIcon name="pause" />{{ t('Stop') }}</button>
					</template>
				</div>
			</div>
			<p v-if="app.reason" class="ap-hint">{{ app.reason }}</p>
		</section>

		<div class="ap-tabset">
			<div class="ap-tabs" role="tablist" :aria-label="t('App')" @keydown="onTabKey">
				<button
					v-for="[name, label] in tabs"
					:id="`app-tab-${name}`"
					:key="name"
					type="button"
					class="ap-tab"
					role="tab"
					:aria-selected="tab === name ? 'true' : 'false'"
					:aria-controls="`app-panel-${name}`"
					:tabindex="tab === name ? 0 : -1"
					@click="selectTab(name)"
				>
					{{ label }}
				</button>
			</div>

			<section v-show="tab === 'overview'" id="app-panel-overview" class="ap-card" role="tabpanel" aria-labelledby="app-tab-overview">
				<dl class="ap-dl">
					<dt>{{ t('Process name') }}</dt>
					<dd class="mono">{{ app.name }}</dd>
					<dt>{{ t('Start') }}</dt>
					<dd class="mono">{{ app.cwd }}/{{ app.script ? `npm run ${app.script}` : app.start }}</dd>
					<dt>{{ t('Runtime') }}</dt>
					<dd class="mono">{{ app.runtime ? `v${app.runtime}` : '—' }}<span v-if="app.nodeVersion === 'default'" class="muted"> · {{ t('default') }}</span></dd>
					<dt>{{ t('Port') }}</dt>
					<dd class="mono">127.0.0.1:{{ app.port }}</dd>
					<dt>{{ t('Template') }}</dt>
					<dd class="mono">{{ app.template }}</dd>
					<dt>{{ t('Instances') }}</dt>
					<dd class="mono">{{ app.instances }}<span v-if="app.instances > 1" class="muted"> · {{ t('cluster mode') }}</span></dd>
					<dt>{{ t('Restart above') }}</dt>
					<dd class="mono">{{ app.maxMemory }} {{ t('MB') }}</dd>
					<template v-if="app.online">
						<dt>{{ t('CPU') }}</dt>
						<dd class="mono">{{ app.cpu }}%</dd>
						<dt>{{ t('Memory') }}</dt>
						<dd class="mono">{{ app.memoryMb }} {{ t('MB') }}</dd>
						<dt>{{ t('Uptime') }}</dt>
						<dd class="mono">{{ formatUptime(app.uptime) }}</dd>
					</template>
					<dt>{{ t('Restarts') }}</dt>
					<dd class="mono">{{ app.restarts }}</dd>
					<dt>{{ t('Created') }}</dt>
					<dd class="mono">{{ app.created }}</dd>
				</dl>
				<div v-if="canManage" class="ap-actions">
					<button type="button" class="ap-btn ap-btn-sm" :disabled="busy || task.running" @click="startTask('install')">
						<ApIcon name="download" />{{ t('Install dependencies') }}
					</button>
					<button
						type="button"
						class="ap-btn ap-btn-sm"
						:disabled="busy || task.running || !scripts.includes('build')"
						:title="scripts.includes('build') ? '' : t('package.json has no build script.')"
						@click="startTask('build', { script: 'build' })"
					>
						<ApIcon name="code" />{{ t('Run build') }}
					</button>
				</div>
				<TaskPanel v-if="task.id && ['app-install', 'app-build'].includes(task.action)" :task="task" :t="t" />
			</section>

			<section v-show="tab === 'logs'" id="app-panel-logs" class="ap-card" role="tabpanel" aria-labelledby="app-tab-logs">
				<AppLogs :api="api" :domain="app.domain" :active="tab === 'logs'" :t="t" />
			</section>

			<section v-if="tab === 'env'" id="app-panel-env" class="ap-card" role="tabpanel" aria-labelledby="app-tab-env">
				<AppEnv :api="api" :domain="app.domain" :can-manage="canManage" :t="t" />
			</section>

			<section v-show="tab === 'settings'" id="app-panel-settings" class="ap-card" role="tabpanel" aria-labelledby="app-tab-settings">
				<AppSettings
					:api="api"
					:app="app"
					:runtimes="initial.runtimes"
					:default-runtime="initial.defaultRuntime"
					:max-instances="initial.maxInstances"
					:memory-limit="initial.memoryLimit"
					:can-manage="canManage"
					:t="t"
					@update="apply"
				/>
			</section>

			<section v-show="tab === 'danger'" id="app-panel-danger" class="ap-card" role="tabpanel" aria-labelledby="app-tab-danger">
				<h3>{{ t('Delete the app') }}</h3>
				<p>{{ t('The app stops, its port is freed and the domain gets its previous template back. Your code stays.') }}</p>
				<label class="ap-check">
					<input v-model="deleting.keepFiles" type="checkbox" :disabled="!canRemove" />
					<span>{{ t('Keep .env and the logs') }}</span>
				</label>
				<div class="ap-actions">
					<button type="button" class="ap-btn ap-btn-danger" :disabled="busy || !canRemove" @click="deleting.open = true">
						<ApIcon name="trash" />{{ t('Delete the app') }}
					</button>
				</div>
			</section>
		</div>

		<ApDialog :open="deleting.open" :title="t('Delete the app')" icon="alert" @close="deleting.open = false">
			<p>{{ t('Delete the Node.js app of %s?', app.domain) }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="deleting.open = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" :disabled="busy" @click="destroy">{{ t('Delete') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
