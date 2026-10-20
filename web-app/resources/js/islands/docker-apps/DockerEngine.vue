<script setup>
// Server → Docker (E4 spec §7): the engine, its disk and the last report of
// the 5-minute check; "Install Docker engine" when it is missing.
import { computed, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createApi } from './api.js';
import DockerTaskPanel from './DockerTaskPanel.vue';
import { engineTaskPlace, engineTiles, findingRepair } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const status = ref(props.initial.status);
const task = ref(props.initial.task ?? {});
const busy = ref(false);
const refreshing = ref(false);

const tiles = computed(() => engineTiles(status.value));
const taskPlace = computed(() => engineTaskPlace(task.value));
const findings = computed(() => status.value.report?.findings ?? []);
const checked = computed(() => Boolean(status.value.report?.time));

async function refresh() {
	refreshing.value = true;
	try {
		status.value = (await api.call('GET', '/status')).status;
	} catch {
		// shown as it came with the page
	} finally {
		refreshing.value = false;
	}
}

async function install() {
	busy.value = true;
	try {
		task.value = (await api.call('POST', '/install')).task;
	} catch (error) {
		api.fail(error);
	} finally {
		busy.value = false;
	}
}

async function finished(value) {
	api.toast(value.status === 'done' ? 'ok' : 'error', value.status === 'done' ? t('The Docker engine is installed.') : t('Failed'));
	task.value = { ...value, running: false };
	await refresh();
}
</script>

<template>
	<div class="ap-stack">
		<div v-if="initial.readOnly" class="ap-alert is-info" role="note">
			<ApIcon name="info" />
			<div class="ap-alert-body"><p>{{ t('Read-only: changes are disabled.') }}</p></div>
		</div>

		<section class="ap-card ap-stack" aria-labelledby="docker-engine-title" :aria-busy="refreshing ? 'true' : 'false'">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="container" />
					<h2 id="docker-engine-title">{{ t('Docker engine') }}</h2>
					<ApBadge :tone="!status.installed ? null : status.running ? 'ok' : 'danger'">
						{{ !status.installed ? t('Not installed') : status.running ? t('Running') : t('Stopped') }}
					</ApBadge>
				</div>
				<div v-if="status.installed" class="ap-actions">
					<button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :disabled="refreshing" @click="refresh">
						<span v-if="refreshing" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="refresh" />{{ t('Refresh') }}
					</button>
				</div>
			</div>

			<template v-if="status.installed">
				<p class="muted">{{ t('The engine runs every Docker app of the server. Users never get access to it: every app runs as its user, without privileges.') }}</p>
				<div v-if="!status.running" class="ap-alert is-danger" role="status">
					<ApIcon name="alert" />
					<div class="ap-alert-body">
						<p>{{ t('The engine is stopped: no Docker app runs until the docker service starts again.') }}</p>
						<p><a :href="initial.servicesUrl">{{ t('Open the Services page') }}</a></p>
					</div>
				</div>
				<dl class="ap-fact-strip">
					<div>
						<dt>{{ t('Version') }}</dt>
						<dd>{{ status.version || '—' }}</dd>
					</div>
					<div>
						<dt>{{ t('Compose') }}</dt>
						<dd>{{ status.composeVersion || '—' }}</dd>
					</div>
					<div>
						<dt>{{ t('Data root') }}</dt>
						<dd>{{ status.dataRoot || '—' }}</dd>
					</div>
				</dl>
				<ul class="ap-stats-tiles ap-stats-tiles-compact" :aria-label="t('Usage')">
					<li v-for="tile in tiles" :key="tile.key" class="ap-stats-tile">
						<span class="ap-stats-tile-icon" aria-hidden="true"><ApIcon :name="tile.icon" /></span>
						<span class="ap-stats-tile-label">{{ t(tile.label) }}</span>
						<span class="ap-stats-tile-value">
							<a v-if="tile.key === 'apps' && initial.appsUrl" class="ap-stat-link" :href="initial.appsUrl">{{ tile.value }}</a>
							<template v-else>{{ tile.value }}</template>
							<small v-if="tile.unit">{{ t(tile.unit) }}</small>
						</span>
					</li>
				</ul>
			</template>

			<div v-else class="ap-empty ap-docker-empty">
				<div class="ap-empty-art">
					<svg class="arcs" viewBox="0 0 88 56" aria-hidden="true"><path d="M4 56a40 40 0 0 1 80 0" /><path d="M16 56a28 28 0 0 1 56 0" /></svg>
					<ApIcon name="container" />
				</div>
				<h3>{{ t('Docker is not installed') }}</h3>
				<p>{{ t('Install the engine to run Docker apps from the catalog. Every app runs as its user, without privileges.') }}</p>
				<div class="ap-actions">
					<button type="button" class="ap-btn ap-btn-primary" :disabled="busy || initial.readOnly || task.running" @click="install">
						<span v-if="busy" class="ap-spinner" aria-hidden="true" /><ApIcon v-else name="download" />{{ t('Install Docker engine') }}
					</button>
				</div>
			</div>

			<DockerTaskPanel v-if="taskPlace === 'main'" :api="api" path="/task" :task="task" :label="t('Installing the Docker engine…')" :t="t" @finish="finished" />
			<div v-else-if="taskPlace === 'last'" class="ap-docker-last">
				<DockerTaskPanel :api="api" path="/task" :task="task" :label="t('Last installation')" :t="t" @finish="finished" />
			</div>
		</section>

		<section v-if="status.installed" class="ap-card ap-card-flush" aria-labelledby="docker-report-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="activity" />
					<h2 id="docker-report-title">{{ t('Findings') }}</h2>
					<ApBadge v-if="findings.length" tone="warn">{{ findings.length }}</ApBadge>
				</div>
				<div class="ap-docker-check muted">
					<ApIcon name="clock" />
					<span v-if="checked">{{ t('Last check') }}: <time class="mono">{{ status.report.time }}</time></span>
					<span v-else>{{ t('Not checked yet') }}</span>
					<span class="ap-docker-check-every">{{ t('Checked every 5 minutes') }}</span>
				</div>
			</div>
			<div v-if="!checked" class="ap-empty ap-docker-empty">
				<div class="ap-empty-art">
					<svg class="arcs" viewBox="0 0 88 56" aria-hidden="true"><path d="M4 56a40 40 0 0 1 80 0" /><path d="M16 56a28 28 0 0 1 56 0" /></svg>
					<ApIcon name="clock" />
				</div>
				<h3>{{ t('Not checked yet') }}</h3>
				<p>{{ t('The first check of the engine and its apps runs within 5 minutes.') }}</p>
			</div>
			<div v-else-if="findings.length === 0" class="ap-empty ap-docker-empty">
				<div class="ap-empty-art is-ok">
					<svg class="arcs" viewBox="0 0 88 56" aria-hidden="true"><path d="M4 56a40 40 0 0 1 80 0" /><path d="M16 56a28 28 0 0 1 56 0" /></svg>
					<ApIcon name="check-circle" />
				</div>
				<h3>{{ t('No problem found') }}</h3>
				<p>{{ t('The engine and every Docker app passed the last check.') }}</p>
			</div>
			<div v-else class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('Findings') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('Type') }}</th>
							<th scope="col">{{ t('User') }}</th>
							<th scope="col">{{ t('App') }}</th>
							<th scope="col">{{ t('Detail') }}</th>
							<th scope="col">{{ t('Repaired') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(finding, index) in findings" :key="index">
							<td :data-label="t('Type')"><ApBadge tone="warn" class="ap-badge-mono">{{ finding.type }}</ApBadge></td>
							<td :data-label="t('User')" class="mono">{{ finding.user || '—' }}</td>
							<td :data-label="t('App')" class="mono">{{ finding.app || '—' }}</td>
							<td :data-label="t('Detail')">{{ finding.detail || '—' }}</td>
							<td :data-label="t('Repaired')">
								<ApBadge v-if="findingRepair(finding.repaired)" :tone="findingRepair(finding.repaired).tone">{{ t(findingRepair(finding.repaired).label) }}</ApBadge>
								<span v-else class="muted">—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</template>
