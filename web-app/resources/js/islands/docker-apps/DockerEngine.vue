<script setup>
// Server → Docker (E4 spec §7): the engine, its disk and the last report of
// the 5-minute check; "Install Docker engine" when it is missing.
import { ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { createApi } from './api.js';
import DockerTaskPanel from './DockerTaskPanel.vue';
import { formatMb } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const status = ref(props.initial.status);
const task = ref(props.initial.task ?? {});
const busy = ref(false);

async function refresh() {
	try {
		status.value = (await api.call('GET', '/status')).status;
	} catch {
		// shown as it came with the page
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
		<section class="ap-card ap-stack" aria-labelledby="docker-engine-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="container" />
					<h2 id="docker-engine-title">{{ t('Docker engine') }}</h2>
					<ApBadge :tone="!status.installed ? null : status.running ? 'ok' : 'danger'">
						{{ !status.installed ? t('Not installed') : status.running ? t('Running') : t('Stopped') }}
					</ApBadge>
				</div>
				<div class="ap-actions">
					<button v-if="!status.installed" type="button" class="ap-btn ap-btn-primary ap-btn-sm" :disabled="busy || initial.readOnly || task.running" @click="install">
						<ApIcon name="download" />{{ t('Install Docker engine') }}
					</button>
				</div>
			</div>
			<p class="muted">{{ t('The engine runs every Docker app of the server. Users never get access to it: every app runs as its user, without privileges.') }}</p>
			<DockerTaskPanel :api="api" path="/task" :task="task" :label="t('Installing the Docker engine…')" :t="t" @finish="finished" />
			<dl v-if="status.installed" class="ap-dl">
				<dt>{{ t('Version') }}</dt>
				<dd class="mono">{{ status.version || '—' }}</dd>
				<dt>{{ t('Compose') }}</dt>
				<dd class="mono">{{ status.composeVersion || '—' }}</dd>
				<dt>{{ t('Data root') }}</dt>
				<dd class="mono">{{ status.dataRoot }}</dd>
				<dt>{{ t('Disk used') }}</dt>
				<dd class="mono">{{ formatMb(status.diskUsed) }}</dd>
				<dt>{{ t('Disk free') }}</dt>
				<dd class="mono">{{ formatMb(status.diskFree) }}</dd>
				<dt>{{ t('Images') }}</dt>
				<dd class="mono">{{ status.images }}</dd>
				<dt>{{ t('Containers') }}</dt>
				<dd class="mono">{{ status.containers }}</dd>
				<dt>{{ t('Apps') }}</dt>
				<dd class="mono">{{ status.apps }}</dd>
				<dt>{{ t('Users') }}</dt>
				<dd class="mono">{{ status.users }}</dd>
			</dl>
		</section>

		<section v-if="status.installed" class="ap-card ap-card-flush" aria-labelledby="docker-report-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="activity" />
					<h2 id="docker-report-title">{{ t('Findings') }}</h2>
					<span class="muted mono">{{ t('Last check') }}: {{ status.report.time || '—' }}</span>
				</div>
			</div>
			<p v-if="status.report.findings.length === 0" class="ap-empty muted">{{ t('No finding.') }}</p>
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
						<tr v-for="(finding, index) in status.report.findings" :key="index">
							<td :data-label="t('Type')" class="mono">{{ finding.type }}</td>
							<td :data-label="t('User')" class="mono">{{ finding.user || '—' }}</td>
							<td :data-label="t('App')" class="mono">{{ finding.app || '—' }}</td>
							<td :data-label="t('Detail')">{{ finding.detail || '—' }}</td>
							<td :data-label="t('Repaired')">{{ finding.repaired || '—' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</template>
