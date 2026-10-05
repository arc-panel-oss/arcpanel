<script setup>
// Docker apps of one account (E4 spec §7): status, resources, start, stop
// and restart, one by one or for the selection. The list is read again
// every 10 seconds while the page is visible.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { appPath, createApi, pageUrl } from './api.js';
import { appStatus } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;

const account = ref(props.initial.account);
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false, engine: false, available: false });
const busy = ref(false);
const selection = ref([]);

const apps = computed(() => account.value.apps ?? []);
const limitLabel = computed(() => (account.value.limit === 'unlimited' ? t('unlimited') : account.value.limit));
const allSelected = computed(() => apps.value.length > 0 && selection.value.length === apps.value.length);

function apply(data) {
	if (data?.account) {
		account.value = data.account;
		selection.value = selection.value.filter((name) => apps.value.some((app) => app.name === name));
	}
	if (data?.abilities) {
		abilities.value = data.abilities;
	}
}

async function refresh() {
	try {
		apply(await api.call('GET', '/apps'));
	} catch {
		// the next round tries again
	}
}

function canRun(action) {
	return action === 'stop' ? abilities.value.remove : abilities.value.manage;
}

async function run(action, names) {
	busy.value = true;
	let failed = 0;
	for (const name of names) {
		try {
			await api.call('POST', appPath(name, `/${action}`));
		} catch (error) {
			failed++;
			api.fail(error);
		}
	}
	busy.value = false;
	if (failed === 0) {
		api.saved();
	}
	await refresh();
}

function toggleAll(event) {
	selection.value = event.target.checked ? apps.value.map((app) => app.name) : [];
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
		<div v-if="!abilities.engine" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body">
				<p>{{ t('Docker apps are not available on this server.') }}</p>
				<a v-if="pages.engine" :href="pages.engine">{{ t('Install the Docker engine') }}</a>
			</div>
		</div>
		<div v-else-if="!abilities.available" class="ap-alert is-warn" role="status">
			<ApIcon name="alert" />
			<div class="ap-alert-body"><p>{{ t('Docker apps are not included in your package.') }}</p></div>
		</div>

		<section class="ap-card ap-card-flush" aria-labelledby="docker-apps-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="container" />
					<h2 id="docker-apps-title">{{ t('Docker apps') }}</h2>
					<span class="muted mono">{{ t('%d of %s apps', apps.length, limitLabel) }}</span>
				</div>
				<div class="ap-actions">
					<template v-if="selection.length > 0">
						<span class="muted">{{ t('%d selected', selection.length) }}</span>
						<button v-if="canRun('start')" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="run('start', selection)">
							<ApIcon name="play" />{{ t('Start') }}
						</button>
						<button v-if="canRun('restart')" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="run('restart', selection)">
							<ApIcon name="rotate" />{{ t('Restart') }}
						</button>
						<button v-if="canRun('stop')" type="button" class="ap-btn ap-btn-sm" :disabled="busy" @click="run('stop', selection)">
							<ApIcon name="pause" />{{ t('Stop') }}
						</button>
					</template>
					<a v-if="abilities.engine" class="ap-btn ap-btn-primary ap-btn-sm" :href="pages.store"><ApIcon name="plus" />{{ t('Open the store') }}</a>
				</div>
			</div>

			<div v-if="apps.length === 0" class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="container" /></div>
				<h3>{{ t('No Docker app yet.') }}</h3>
				<p v-if="abilities.engine && abilities.available"><a :href="pages.store">{{ t('Open the store') }}</a></p>
			</div>
			<div v-else class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('Docker apps') }}</caption>
					<thead>
						<tr>
							<th scope="col" class="col-check">
								<label class="ap-check"><input type="checkbox" :checked="allSelected" :aria-label="t('Select all')" @change="toggleAll" /></label>
							</th>
							<th scope="col">{{ t('App name') }}</th>
							<th scope="col">{{ t('Web domain') }}</th>
							<th scope="col">{{ t('Status') }}</th>
							<th scope="col">{{ t('Version') }}</th>
							<th scope="col" class="col-num">{{ t('CPU') }}</th>
							<th scope="col" class="col-num">{{ t('Memory') }}</th>
							<th scope="col" class="col-actions"><span class="sr-only">{{ t('Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="app in apps" :key="app.name">
							<td class="col-check">
								<label class="ap-check"><input v-model="selection" type="checkbox" :value="app.name" :aria-label="t('Select %s', app.name)" /></label>
							</td>
							<td :data-label="t('App name')">
								<a :href="pageUrl(pages.show, app.name)" class="mono">{{ app.name }}</a>
								<div class="muted">{{ app.appName }}</div>
							</td>
							<td :data-label="t('Web domain')">
								<template v-if="app.domain">
									<a :href="app.url" class="mono" target="_blank" rel="noopener">{{ app.domain }}</a>
									<ApBadge v-if="app.ssl" tone="ok">HTTPS</ApBadge>
								</template>
								<span v-else class="muted">—</span>
							</td>
							<td :data-label="t('Status')">
								<ApBadge :tone="appStatus(app).tone">{{ t(appStatus(app).label) }}</ApBadge>
								<div v-if="app.reason" class="muted">{{ app.reason }}</div>
							</td>
							<td :data-label="t('Version')" class="mono">
								{{ app.version }}
								<ApBadge v-if="app.update" tone="info" :title="t('A new version is available.')">{{ t('Update') }}</ApBadge>
							</td>
							<td :data-label="t('CPU')" class="col-num mono">{{ app.status === 'running' ? `${app.cpu}%` : '—' }}</td>
							<td :data-label="t('Memory')" class="col-num mono">{{ app.status === 'running' ? `${app.memoryMb} ${t('MB')}` : '—' }}</td>
							<td class="col-actions">
								<div class="ap-row ap-row-end">
									<button
										v-if="app.status !== 'running' && !app.busy && !app.suspended && canRun('start')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Start')} ${app.name}`"
										:title="t('Start')"
										:disabled="busy"
										@click="run('start', [app.name])"
									>
										<ApIcon name="play" />
									</button>
									<button
										v-if="app.status === 'running' && canRun('stop')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Stop')} ${app.name}`"
										:title="t('Stop')"
										:disabled="busy"
										@click="run('stop', [app.name])"
									>
										<ApIcon name="pause" />
									</button>
									<a class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon" :href="pageUrl(pages.show, app.name)" :aria-label="`${t('Open')} ${app.name}`" :title="t('Open')">
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
				<span v-if="abilities.manage && !account.hasRoom">{{ t('Your package allows no more apps.') }}</span>
			</div>
		</section>
	</div>
</template>
