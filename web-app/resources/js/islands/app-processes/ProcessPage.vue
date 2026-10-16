<script setup>
// Page of one app process (E42 spec §9): state and actions, then the tabs
// Overview, Logs, Environment, Settings, Tasks and Danger zone. The state
// is read again every 5 seconds.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AppEnv from '../node-apps/AppEnv.vue';
import AppLogs from '../node-apps/AppLogs.vue';
import { createApi, fieldErrors } from '../node-apps/api.js';
import ApBadge from '../shared/ApBadge.vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createTaskPoller } from '../shared/task-poller.js';
import { formatUptime, isCommand, isCpus, isRelativePath, processPath, processStatus, RESERVED_ENV } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const process = ref(props.initial.process);
const task = ref(props.initial.task ?? null);
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const busy = ref(false);
const deleting = ref(false);
const settings = reactive({ start: process.value.start, cwd: process.value.cwd, memory: process.value.memory, cpus: process.value.cpus });
const settingErrors = ref({});
const taskForm = reactive({ task: 'composer-install', command: '' });

const tabs = [
	['overview', t('Overview')],
	['logs', t('Logs')],
	['env', t('Environment')],
	['settings', t('Settings')],
	['tasks', t('Tasks')],
	['danger', t('Danger zone')],
];
const initialTab = tabs.some(([name]) => `#${name}` === window.location.hash) ? window.location.hash.slice(1) : 'overview';
const tab = ref(initialTab);
const taskNames = [
	['composer-install', t('composer install')],
	['migrate', t('php artisan migrate')],
	['pip-install', t('pip install -r requirements.txt')],
	['bundle-install', t('bundle install')],
	['custom', t('Custom command')],
];

const canManage = computed(() => abilities.value.manage && !process.value.suspended);
const canRemove = computed(() => abilities.value.remove);
const status = computed(() => processStatus(process.value));
const path = computed(() => processPath(process.value));
const logStreams = [
	['process', t('Process')],
	['task', t('Task')],
];

function apply(data) {
	if (data?.process) {
		process.value = data.process;
	}
	if (data && 'task' in data) {
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

async function saveCommand() {
	if (!isCommand(settings.start) || !isRelativePath(settings.cwd)) {
		return;
	}
	busy.value = true;
	settingErrors.value = {};
	try {
		apply(await api.call('PUT', `${path.value}/start`, { start: settings.start, cwd: settings.cwd }));
		api.saved(t('Saved; the process was restarted when it ran.'));
	} catch (error) {
		settingErrors.value = fieldErrors(error);
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

async function saveLimits() {
	if (!isCpus(settings.cpus) || Number(settings.memory) < 32) {
		return;
	}
	busy.value = true;
	settingErrors.value = {};
	try {
		apply(await api.call('PUT', `${path.value}/limits`, { memory_mb: Number(settings.memory), cpus: String(settings.cpus) }));
		api.saved(t('Saved; the process was restarted when it ran.'));
	} catch (error) {
		settingErrors.value = fieldErrors(error);
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

const poller = createTaskPoller({
	fetchTask: async () => (await api.call('GET', '/task')).task ?? { status: 'done' },
	onUpdate: (value) => {
		task.value = value;
	},
	onFinish: async (value) => {
		api.toast(value.status === 'done' ? 'ok' : 'error', value.status === 'done' ? t('Done') : t('Failed'));
		await refresh();
	},
	onError: () => {},
});

async function startTask() {
	busy.value = true;
	try {
		const body = taskForm.task === 'custom' ? { task: 'custom', command: taskForm.command } : { task: taskForm.task };
		const data = await api.call('POST', `${path.value}/tasks`, body);
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
		await api.call('DELETE', path.value);
		window.location.assign(pages.list);
	} catch (error) {
		api.fail(error);
		busy.value = false;
	}
	deleting.value = false;
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
		document.getElementById(`process-tab-${next}`)?.focus();
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

onMounted(() => {
	schedule();
	if (task.value?.running) {
		poller.start();
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
		<div v-if="process.suspended" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ t('This process is suspended.') }}</p></div>
		</div>
		<div v-if="process.runtimeMissing" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ process.runtimeMissing }}</p></div>
		</div>

		<section class="ap-card" aria-labelledby="process-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="terminal" />
					<h2 id="process-title" class="mono">{{ process.domain }}/{{ process.name }}</h2>
					<ApBadge :tone="status.tone">{{ t(status.label) }}</ApBadge>
				</div>
				<div class="ap-actions">
					<button v-if="!process.running && canManage" type="button" class="ap-btn ap-btn-sm ap-btn-primary" :disabled="busy" @click="act('start')">
						<ApIcon name="play" />{{ t('Start') }}
					</button>
					<template v-if="process.running">
						<button v-if="canManage" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('reload')"><ApIcon name="refresh" />{{ t('Reload') }}</button>
						<button v-if="canManage" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('restart')"><ApIcon name="rotate" />{{ t('Restart') }}</button>
						<button v-if="canRemove" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="act('stop')"><ApIcon name="pause" />{{ t('Stop') }}</button>
					</template>
				</div>
			</div>
			<p v-if="process.status === 'failed'" class="ap-hint">{{ t('The process stopped after too many restarts; read its log, then start it again.') }}</p>
		</section>

		<div class="ap-tabset">
			<div class="ap-tabs" role="tablist" :aria-label="t('Process')" @keydown="onTabKey">
				<button
					v-for="[name, label] in tabs"
					:id="`process-tab-${name}`"
					:key="name"
					type="button"
					class="ap-tab"
					role="tab"
					:aria-selected="tab === name ? 'true' : 'false'"
					:aria-controls="`process-panel-${name}`"
					:tabindex="tab === name ? 0 : -1"
					@click="selectTab(name)"
				>
					{{ label }}
				</button>
			</div>

			<section v-show="tab === 'overview'" id="process-panel-overview" class="ap-card" role="tabpanel" aria-labelledby="process-tab-overview">
				<dl class="ap-dl">
					<dt>{{ t('Kind') }}</dt>
					<dd>{{ process.kind === 'web' ? t('Web process') : t('Worker') }}</dd>
					<dt>{{ t('Preset') }}</dt>
					<dd class="mono">{{ process.preset }}</dd>
					<dt>{{ t('Command') }}</dt>
					<dd class="mono">{{ process.start }}</dd>
					<dt>{{ t('Working directory') }}</dt>
					<dd class="mono">{{ process.cwd }}</dd>
					<template v-if="process.kind === 'web'">
						<dt>{{ t('Port') }}</dt>
						<dd class="mono">127.0.0.1:{{ process.port }}</dd>
						<dt>{{ t('Template') }}</dt>
						<dd class="mono">{{ process.template }}</dd>
					</template>
					<dt>{{ t('Limits') }}</dt>
					<dd class="mono">{{ process.memory }} {{ t('MB') }} · {{ process.cpus }} {{ t('CPUs') }}</dd>
					<template v-if="process.running">
						<dt>{{ t('PID') }}</dt>
						<dd class="mono">{{ process.pid }}</dd>
						<dt>{{ t('Memory') }}</dt>
						<dd class="mono">{{ process.memoryUsed }} {{ t('MB') }}</dd>
						<dt>{{ t('Uptime') }}</dt>
						<dd class="mono">{{ formatUptime(process.uptime) }}</dd>
					</template>
					<dt>{{ t('CPU time') }}</dt>
					<dd class="mono">{{ process.cpuSeconds }} s</dd>
					<dt>{{ t('Restarts') }}</dt>
					<dd class="mono">{{ process.restarts }}</dd>
					<dt>{{ t('Reload') }}</dt>
					<dd class="mono">{{ process.reload }}</dd>
					<dt>{{ t('Created') }}</dt>
					<dd class="mono">{{ process.created }}</dd>
				</dl>
			</section>

			<section v-show="tab === 'logs'" id="process-panel-logs" class="ap-card" role="tabpanel" aria-labelledby="process-tab-logs">
				<AppLogs :api="api" :domain="process.domain" :name="process.name" :path="`${path}/logs`" :streams="logStreams" :active="tab === 'logs'" :t="t" />
			</section>

			<section v-if="tab === 'env'" id="process-panel-env" class="ap-card" role="tabpanel" aria-labelledby="process-tab-env">
				<AppEnv
					:api="api"
					:domain="process.domain"
					:path="`${path}/env`"
					:reserved="RESERVED_ENV"
					:hint="t('Variables of the process. PORT and HOST of a web process, PATH, HOME, USER and LOGNAME are set by the panel.')"
					:saved-message="t('Saved; the process was restarted when it ran.')"
					:can-manage="canManage"
					:secrets="initial.secrets ?? []"
					:t="t"
				/>
			</section>

			<section v-show="tab === 'settings'" id="process-panel-settings" class="ap-card" role="tabpanel" aria-labelledby="process-tab-settings">
				<form class="ap-stack" novalidate @submit.prevent="saveCommand">
					<h3>{{ t('Command') }}</h3>
					<div class="ap-field" :class="{ 'is-invalid': settingErrors.start || !isCommand(settings.start) }">
						<label class="ap-label" for="process-setting-start">{{ t('Command') }}</label>
						<textarea id="process-setting-start" v-model="settings.start" class="ap-input ap-input-mono" rows="3" spellcheck="false" :disabled="!canManage" />
						<span v-if="settingErrors.start" class="ap-error">{{ settingErrors.start }}</span>
					</div>
					<div class="ap-field" :class="{ 'is-invalid': settingErrors.cwd || !isRelativePath(settings.cwd) }">
						<label class="ap-label" for="process-setting-cwd">{{ t('Working directory') }}</label>
						<input id="process-setting-cwd" v-model="settings.cwd" class="ap-input ap-input-mono" type="text" spellcheck="false" :disabled="!canManage" />
					</div>
					<div v-if="canManage" class="ap-actions">
						<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy || !isCommand(settings.start) || !isRelativePath(settings.cwd)">
							<ApIcon name="save" />{{ t('Save') }}
						</button>
					</div>
				</form>
				<form class="ap-stack" novalidate @submit.prevent="saveLimits">
					<h3>{{ t('Limits') }}</h3>
					<div class="ap-form-grid">
						<div class="ap-field" :class="{ 'is-invalid': settingErrors.memory_mb }">
							<label class="ap-label" for="process-setting-memory">{{ t('Memory (MB)') }}</label>
							<input id="process-setting-memory" v-model.number="settings.memory" class="ap-input ap-input-mono" type="number" min="32" :disabled="!canManage" />
							<span v-if="settingErrors.memory_mb" class="ap-error">{{ settingErrors.memory_mb }}</span>
						</div>
						<div class="ap-field" :class="{ 'is-invalid': settingErrors.cpus || !isCpus(settings.cpus) }">
							<label class="ap-label" for="process-setting-cpus">{{ t('CPUs') }}</label>
							<input id="process-setting-cpus" v-model="settings.cpus" class="ap-input ap-input-mono" type="text" inputmode="decimal" :disabled="!canManage" />
							<span v-if="settingErrors.cpus" class="ap-error">{{ settingErrors.cpus }}</span>
						</div>
					</div>
					<div v-if="canManage" class="ap-actions">
						<button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy || !isCpus(settings.cpus)"><ApIcon name="save" />{{ t('Save') }}</button>
					</div>
				</form>
			</section>

			<section v-show="tab === 'tasks'" id="process-panel-tasks" class="ap-card" role="tabpanel" aria-labelledby="process-tab-tasks">
				<p class="muted">{{ t('A task runs once as your user in the working directory of the process; after an install the process is reloaded.') }}</p>
				<form v-if="canManage" class="ap-stack" novalidate @submit.prevent="startTask">
					<div class="ap-form-grid">
						<div class="ap-field">
							<label class="ap-label" for="process-task">{{ t('Task') }}</label>
							<div class="ap-select-wrap">
								<select id="process-task" v-model="taskForm.task" class="ap-select">
									<option v-for="[name, label] in taskNames" :key="name" :value="name">{{ label }}</option>
								</select>
								<ApIcon name="chevron-down" size="sm" />
							</div>
						</div>
						<div v-if="taskForm.task === 'custom'" class="ap-field">
							<label class="ap-label" for="process-task-command">{{ t('Command') }}</label>
							<input id="process-task-command" v-model="taskForm.command" class="ap-input ap-input-mono" type="text" spellcheck="false" />
						</div>
					</div>
					<div class="ap-actions">
						<button
							type="submit"
							class="ap-btn ap-btn-sm"
							:disabled="busy || task?.running || (taskForm.task === 'custom' && !isCommand(taskForm.command))"
						>
							<ApIcon name="play" />{{ t('Run') }}
						</button>
					</div>
				</form>
				<dl v-if="task" class="ap-dl">
					<dt>{{ t('Last task') }}</dt>
					<dd class="mono">{{ task.domain }}/{{ task.name }} · {{ task.task }}</dd>
					<dt>{{ t('Status') }}</dt>
					<dd>{{ task.status }}<span v-if="task.exit !== ''" class="muted mono"> · {{ t('exit %s', task.exit) }}</span></dd>
					<dt>{{ t('Started') }}</dt>
					<dd class="mono">{{ task.start }}</dd>
				</dl>
				<p class="muted">{{ t('The output of the last task is in the Task stream of the logs.') }}</p>
			</section>

			<section v-show="tab === 'danger'" id="process-panel-danger" class="ap-card" role="tabpanel" aria-labelledby="process-tab-danger">
				<h3>{{ t('Delete the process') }}</h3>
				<p>{{ t('The process stops and is removed; a web process frees its port and the domain gets its previous template back. Your code, the environment file and the logs stay.') }}</p>
				<div class="ap-actions">
					<button type="button" class="ap-btn ap-btn-danger" :disabled="busy || !canRemove" @click="deleting = true">
						<ApIcon name="trash" />{{ t('Delete the process') }}
					</button>
				</div>
			</section>
		</div>

		<ApDialog :open="deleting" :title="t('Delete the process')" icon="alert" @close="deleting = false">
			<p>{{ t('Delete the app process %s?', `${process.domain}/${process.name}`) }}</p>
			<template #footer>
				<button type="button" class="ap-btn ap-btn-ghost" data-autofocus @click="deleting = false">{{ t('Cancel') }}</button>
				<button type="button" class="ap-btn ap-btn-danger" :disabled="busy" @click="destroy">{{ t('Delete') }}</button>
			</template>
		</ApDialog>
	</div>
</template>
