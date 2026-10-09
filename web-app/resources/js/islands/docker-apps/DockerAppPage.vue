<script setup>
// Page of one Docker app (E4 spec §7): state and actions, then the tabs
// Overview (services, ports, the task), Logs, Settings, Updates, Backups
// and Danger zone. The state is read again every 5 seconds.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { appPath, createApi } from './api.js';
import DockerLogs from './DockerLogs.vue';
import DockerSettings from './DockerSettings.vue';
import DockerTaskPanel from './DockerTaskPanel.vue';
import { appStatus } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const app = ref(props.initial.app);
const task = ref(props.initial.task ?? {});
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const busy = ref(false);
const deleting = ref({ open: false, keepData: true });

const tabs = [
	['overview', t('Overview')],
	['logs', t('Logs')],
	['settings', t('Settings')],
	['updates', t('Updates')],
	['backups', t('Backups')],
	['danger', t('Danger zone')],
];
const initialTab = tabs.some(([name]) => `#${name}` === window.location.hash) ? window.location.hash.slice(1) : 'overview';
const tab = ref(initialTab);

const canManage = computed(() => abilities.value.manage && !app.value.suspended && !app.value.busy && !task.value.running);
const canRemove = computed(() => abilities.value.remove && !task.value.running);
const status = computed(() => appStatus(app.value));
const running = computed(() => app.value.status === 'running' || app.value.status === 'degraded' || app.value.status === 'restarting');
const taskLabels = computed(() => ({
	install: t('Install %s', app.value.appName),
	update: t('Update to %s', task.value.args || app.value.latest),
	rollback: t('Roll back to %s', app.value.prevVersion || ''),
	snapshot: t('Snapshot now'),
}));
// File manager (E3): the data folder of the app
const folderUrl = computed(() => (pages.files ? `${pages.files}?path=${['apps', app.value.name, 'data'].map(encodeURIComponent).join('/')}` : null));

function apply(data) {
	if (data?.app) {
		app.value = data.app;
		if (data.app.task && Object.keys(data.app.task).length > 0 && data.app.task.id !== task.value.id) {
			task.value = { ...data.app.task, running: data.app.task.status === 'running', log: '', offset: 0 };
		}
	}
	if (data?.abilities) {
		abilities.value = data.abilities;
	}
}

async function refresh() {
	try {
		apply(await api.call('GET', appPath(app.value.name)));
	} catch {
		// tried again on the next round
	}
}

async function act(action) {
	busy.value = true;
	try {
		apply(await api.call('POST', appPath(app.value.name, `/${action}`)));
		api.saved();
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

async function startTask(action, version = undefined) {
	busy.value = true;
	try {
		const data = await api.call('POST', appPath(app.value.name, '/tasks'), version ? { action, version } : { action });
		task.value = data.task;
		await refresh();
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

async function taskFinished(value) {
	api.toast(value.status === 'done' ? 'ok' : 'error', value.status === 'done' ? t('Done') : t('Failed'));
	task.value = { ...value, running: false };
	await refresh();
}

async function destroy() {
	busy.value = true;
	try {
		await api.call('DELETE', appPath(app.value.name), deleting.value.keepData ? { keep_data: true } : undefined);
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

onMounted(schedule);
onBeforeUnmount(() => clearTimeout(timer));
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
					<span class="ap-pick-logo">
						<img v-if="app.catalog.icon" :src="app.catalog.icon" alt="" />
						<template v-else>{{ app.appName.slice(0, 2) }}</template>
					</span>
					<h2 id="app-title" class="mono">{{ app.name }}</h2>
					<span class="muted">{{ app.appName }} {{ app.version }}</span>
					<ApBadge :tone="status.tone">
						<span v-if="app.busy" class="ap-spinner" aria-hidden="true" />
						{{ t(status.label) }}
					</ApBadge>
				</div>
				<div class="ap-actions">
					<a v-if="folderUrl" class="ap-btn ap-btn-ghost ap-btn-sm" :href="folderUrl"><ApIcon name="folder" />{{ t('Open data folder') }}</a>
					<a v-if="app.url" class="ap-btn ap-btn-ghost ap-btn-sm" :href="app.url" target="_blank" rel="noopener">
						<ApIcon name="external" />{{ app.url }}
						<ApBadge v-if="app.ssl" tone="ok">HTTPS</ApBadge>
					</a>
					<button v-if="!running && canManage" type="button" class="ap-btn ap-btn-sm ap-btn-primary" :disabled="busy" @click="act('start')">
						<ApIcon name="play" />{{ t('Start') }}
					</button>
					<template v-if="running">
						<button v-if="canManage" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('restart')"><ApIcon name="rotate" />{{ t('Restart') }}</button>
						<button v-if="canRemove" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('stop')"><ApIcon name="pause" />{{ t('Stop') }}</button>
					</template>
				</div>
			</div>
			<p v-if="app.reason" class="ap-hint">{{ app.reason }}</p>
			<div v-if="app.status === 'failed' && app.reason !== 'update' && abilities.manage && !task.running" class="ap-actions">
				<button v-if="app.reason === 'pull' || app.reason === 'health' || app.reason === 'start' || app.reason === 'timeout'" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="startTask('install')">
					<ApIcon name="refresh" />{{ t('Install') }}
				</button>
			</div>
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

			<section v-show="tab === 'overview'" id="app-panel-overview" class="ap-card ap-stack" role="tabpanel" aria-labelledby="app-tab-overview">
				<div v-if="app.postInstall" class="ap-alert is-info" role="note">
					<ApIcon name="info" />
					<div class="ap-alert-body"><p>{{ app.postInstall }}</p></div>
				</div>
				<DockerTaskPanel :api="api" :path="appPath(app.name, '/task')" :task="task" :label="taskLabels[task.action] || t('Task')" :t="t" @finish="taskFinished" />
				<div class="ap-table-wrap">
					<table class="ap-table">
						<caption class="sr-only">{{ t('Services') }}</caption>
						<thead>
							<tr>
								<th scope="col">{{ t('Service') }}</th>
								<th scope="col">{{ t('Status') }}</th>
								<th scope="col">{{ t('Health') }}</th>
								<th scope="col">{{ t('Image') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="service in app.services" :key="service.service">
								<td class="mono">{{ service.service }}</td>
								<td>{{ service.status || service.state }}</td>
								<td>{{ service.health || '—' }}</td>
								<td class="mono muted">{{ service.image.split('@')[0] }}</td>
							</tr>
							<tr v-if="app.services.length === 0">
								<td colspan="4" class="muted">—</td>
							</tr>
						</tbody>
					</table>
				</div>
				<dl class="ap-dl">
					<template v-for="port in app.ports" :key="port.name">
						<dt>{{ t('Port') }} {{ port.name }}</dt>
						<dd class="mono">
							{{ port.public ? '0.0.0.0' : '127.0.0.1' }}:{{ port.port }} → {{ port.container }}
							<ApBadge :tone="port.public ? 'warn' : null">{{ port.public ? t('Public') : t('Private') }}</ApBadge>
							<span v-if="port.description" class="muted"> {{ port.description }}</span>
						</dd>
					</template>
					<dt>{{ t('Limits') }}</dt>
					<dd class="mono">{{ app.cpus }} {{ t('CPUs') }} · {{ app.memory }} {{ t('MB') }}</dd>
					<template v-if="running">
						<dt>{{ t('CPU') }}</dt>
						<dd class="mono">{{ app.cpu }}%</dd>
						<dt>{{ t('Memory') }}</dt>
						<dd class="mono">{{ app.memoryMb }} {{ t('MB') }}</dd>
					</template>
				</dl>
				<p v-if="pages.ports && app.ports.length > 1" class="muted">
					{{ t('Ports are private to the server unless made public on the Ports page.') }} <a :href="pages.ports">{{ t('Ports') }}</a>
				</p>
			</section>

			<section v-show="tab === 'logs'" id="app-panel-logs" class="ap-card" role="tabpanel" aria-labelledby="app-tab-logs">
				<DockerLogs :api="api" :name="app.name" :services="app.services" :active="tab === 'logs'" :t="t" />
			</section>

			<section v-if="tab === 'settings'" id="app-panel-settings" class="ap-card" role="tabpanel" aria-labelledby="app-tab-settings">
				<DockerSettings :api="api" :app="app" :domains="initial.domains" :secrets="initial.secrets ?? []" :can-manage="canManage" :t="t" @update="apply" />
			</section>

			<section v-show="tab === 'updates'" id="app-panel-updates" class="ap-card ap-stack" role="tabpanel" aria-labelledby="app-tab-updates">
				<dl class="ap-dl">
					<dt>{{ t('Version') }}</dt>
					<dd class="mono">{{ app.version }}</dd>
					<dt>{{ t('Latest version') }}</dt>
					<dd class="mono">{{ app.latest }} <ApBadge v-if="app.update" tone="info">{{ t('A new version is available.') }}</ApBadge></dd>
					<template v-if="app.catalog.changelog">
						<dt>{{ t('Changelog') }}</dt>
						<dd><a :href="app.catalog.changelog" target="_blank" rel="noopener">{{ app.catalog.changelog }}</a></dd>
					</template>
				</dl>
				<p class="muted">{{ t('Updating stops the app, saves its data and starts the new version; it rolls back by itself when the new version fails.') }}</p>
				<p v-if="app.rollback" class="muted">{{ t('The data of %s is kept for a rollback.', app.prevVersion) }}</p>
				<div class="ap-actions">
					<button v-if="app.update" type="button" class="ap-btn ap-btn-primary" :disabled="busy || !canManage" @click="startTask('update', app.latest)">
						<ApIcon name="download" />{{ t('Update to %s', app.latest) }}
					</button>
					<button v-if="app.rollback" type="button" class="ap-btn" :disabled="busy || !canManage" @click="startTask('rollback')">
						<ApIcon name="history" />{{ t('Roll back to %s', app.prevVersion) }}
					</button>
				</div>
			</section>

			<section v-show="tab === 'backups'" id="app-panel-backups" class="ap-card ap-stack" role="tabpanel" aria-labelledby="app-tab-backups">
				<p class="muted">{{ t('The nightly backup includes the data folder of the app; databases are dumped into data/.dumps 15 minutes before it.') }}</p>
				<template v-if="app.catalog.dumps">
					<dl class="ap-dl">
						<dt>{{ t('Last dump') }}</dt>
						<dd class="mono">{{ app.lastDump || t('Never') }}</dd>
					</dl>
					<div class="ap-actions">
						<button type="button" class="ap-btn" :disabled="busy || !canManage || !running" @click="startTask('snapshot')">
							<ApIcon name="db" />{{ t('Snapshot now') }}
						</button>
					</div>
				</template>
				<p v-else class="muted">{{ t('This app has no database to dump: its data folder is backed up as it is.') }}</p>
			</section>

			<section v-show="tab === 'danger'" id="app-panel-danger" class="ap-card" role="tabpanel" aria-labelledby="app-tab-danger">
				<h3>{{ t('Delete the app') }}</h3>
				<p>{{ t('The app stops, its ports are freed and the domain gets its previous template back.') }}</p>
				<label class="ap-check">
					<input v-model="deleting.keepData" type="checkbox" :disabled="!canRemove" />
					<span>{{ t('Keep the data folder') }}</span>
				</label>
				<p v-if="!deleting.keepData" class="ap-hint">{{ t('The data folder is removed too.') }}</p>
				<div class="ap-actions">
					<button type="button" class="ap-btn ap-btn-danger" :disabled="busy || !canRemove" @click="deleting.open = true">
						<ApIcon name="trash" />{{ t('Delete the app') }}
					</button>
				</div>
			</section>
		</div>

		<ApDialog :open="deleting.open" :title="t('Delete the app')" icon="alert" @close="deleting.open = false">
			<p>{{ t('Delete the Docker app %s?', app.name) }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="deleting.open = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" :disabled="busy" @click="destroy">{{ t('Delete') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
