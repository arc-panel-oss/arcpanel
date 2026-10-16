<script setup>
// App processes of one account (E42 spec §9): status, resources, start,
// stop and restart. The list is read again every 10 seconds while the page
// is visible. With props.domain (the web domain page) only that domain's;
// otherwise filters by domain and status.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { createApi } from '../node-apps/api.js';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { filterProcesses, formatUptime, processPath, processStatus, processUrl } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;
const domain = props.initial.domain ?? '';

const account = ref(props.initial.account);
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const busy = ref(false);
const filter = ref({ domain: '', status: '' });

const all = computed(() => filterProcesses(account.value.processes ?? [], domain, ''));
const processes = computed(() => filterProcesses(all.value, domain === '' ? filter.value.domain : domain, filter.value.status));
const domains = computed(() => [...new Set(all.value.map((process) => process.domain))].sort());
const statuses = [
	['running', t('Running')],
	['stopped', t('Stopped')],
	['failed', t('Failed')],
	['suspended', t('Suspended')],
];
const limitLabel = computed(() => (account.value.limit === 'unlimited' ? t('unlimited') : account.value.limit));
const canCreate = computed(() => abilities.value.manage && account.value.allowed && account.value.hasRoom);
const createUrl = computed(() => (domain === '' ? pages.create : `${pages.create}?domain=${encodeURIComponent(domain)}`));

function canRun(action) {
	return action === 'stop' ? abilities.value.remove : abilities.value.manage && account.value.allowed;
}

async function refresh() {
	try {
		const data = await api.call('GET');
		account.value = data.account;
		abilities.value = data.abilities;
	} catch {
		// the next round tries again
	}
}

async function run(action, process) {
	busy.value = true;
	try {
		await api.call('POST', `${processPath(process)}/${action}`);
		api.saved();
	} catch (error) {
		api.fail(error);
	}
	busy.value = false;
	await refresh();
}

let timer = null;
function schedule() {
	timer = setTimeout(async () => {
		if (document.visibilityState === 'visible' && !busy.value) {
			await refresh();
		}
		schedule();
	}, 10000);
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
		<div v-if="!account.allowed" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ t('App processes are not included in your package.') }}</p></div>
		</div>

		<section class="ap-card ap-card-flush" aria-labelledby="app-processes-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="terminal" />
					<h2 id="app-processes-title">{{ t('App processes') }}</h2>
					<span class="muted mono">{{ t('%d of %s processes', account.count, limitLabel) }}</span>
				</div>
				<div class="ap-actions">
					<a v-if="canCreate" class="ap-btn ap-btn-primary ap-btn-sm" :href="createUrl"><ApIcon name="plus" />{{ t('Add process') }}</a>
					<button
						v-else-if="abilities.manage && account.allowed"
						type="button"
						class="ap-btn ap-btn-primary ap-btn-sm"
						disabled
						:title="t('Your package allows no more processes.')"
					>
						<ApIcon name="plus" />{{ t('Add process') }}
					</button>
				</div>
			</div>

			<div v-if="all.length > 0" class="ap-toolbar">
				<label v-if="domain === '' && domains.length > 1" class="ap-row">
					<span class="ap-label">{{ t('Domain') }}</span>
					<select v-model="filter.domain" class="ap-input ap-input-sm">
						<option value="">{{ t('All domains') }}</option>
						<option v-for="name in domains" :key="name" :value="name">{{ name }}</option>
					</select>
				</label>
				<label class="ap-row">
					<span class="ap-label">{{ t('Status') }}</span>
					<select v-model="filter.status" class="ap-input ap-input-sm">
						<option value="">{{ t('All statuses') }}</option>
						<option v-for="[value, label] in statuses" :key="value" :value="value">{{ label }}</option>
					</select>
				</label>
			</div>

			<p v-if="all.length > 0 && processes.length === 0" class="ap-card-body muted">{{ t('No process matches the filters.') }}</p>
			<div v-else-if="processes.length === 0" class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="terminal" /></div>
				<h3>{{ t('No app process yet.') }}</h3>
				<p>{{ t('An app process keeps a server or a worker of your domain running: Laravel Octane, a queue worker, Gunicorn, Puma or your own command.') }}</p>
			</div>
			<div v-else class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('App processes') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('Process') }}</th>
							<th scope="col">{{ t('Status') }}</th>
							<th scope="col">{{ t('Preset') }}</th>
							<th scope="col" class="col-num">{{ t('Memory') }}</th>
							<th scope="col" class="col-num">{{ t('Restarts') }}</th>
							<th scope="col" class="col-num">{{ t('Uptime') }}</th>
							<th scope="col" class="col-actions"><span class="sr-only">{{ t('Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="process in processes" :key="`${process.domain}/${process.name}`">
							<td :data-label="t('Process')">
								<a :href="processUrl(pages.show, process)" class="mono">{{ process.domain }}/{{ process.name }}</a>
								<div class="muted mono">{{ process.kind === 'web' ? t('web · port %s', process.port) : t('worker') }}</div>
							</td>
							<td :data-label="t('Status')">
								<ApBadge :tone="processStatus(process).tone">{{ t(processStatus(process).label) }}</ApBadge>
							</td>
							<td :data-label="t('Preset')" class="mono">{{ process.preset }}</td>
							<td :data-label="t('Memory')" class="col-num mono">{{ process.running ? `${process.memoryUsed} / ${process.memory} ${t('MB')}` : `${process.memory} ${t('MB')}` }}</td>
							<td :data-label="t('Restarts')" class="col-num mono">{{ process.restarts }}</td>
							<td :data-label="t('Uptime')" class="col-num mono">{{ process.running ? formatUptime(process.uptime) : '—' }}</td>
							<td class="col-actions">
								<div class="ap-row ap-row-end">
									<button
										v-if="!process.running && canRun('start') && !process.suspended"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Start')} ${process.domain}/${process.name}`"
										:title="t('Start')"
										:disabled="busy"
										@click="run('start', process)"
									>
										<ApIcon name="play" />
									</button>
									<button
										v-if="process.running && canRun('restart')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Restart')} ${process.domain}/${process.name}`"
										:title="t('Restart')"
										:disabled="busy"
										@click="run('restart', process)"
									>
										<ApIcon name="rotate" />
									</button>
									<button
										v-if="process.running && canRun('stop')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Stop')} ${process.domain}/${process.name}`"
										:title="t('Stop')"
										:disabled="busy"
										@click="run('stop', process)"
									>
										<ApIcon name="pause" />
									</button>
									<a
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:href="processUrl(pages.show, process)"
										:aria-label="`${t('Open')} ${process.domain}/${process.name}`"
										:title="t('Open')"
									>
										<ApIcon name="chevron-right" />
									</a>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="ap-card-foot">
				<span>{{ t('%d running · %d stopped · %d failed', account.totals.running, account.totals.stopped, account.totals.failed) }}</span>
				<span v-if="abilities.manage && !account.hasRoom">{{ t('Your package allows no more processes.') }}</span>
			</div>
		</section>
	</div>
</template>
