<script setup>
// Node.js apps of one account (E14 spec §7): status, resources, start, stop
// and restart, one by one or for the selection. The list is read again
// every 10 seconds while the page is visible.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { appUrl, createApi } from './api.js';
import { appStatus, formatUptime } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const api = createApi(props.initial.base, t);
const pages = props.initial.pages;
const embedded = Boolean(props.initial.embedded);

const account = ref(props.initial.account);
const abilities = ref(props.initial.abilities ?? { manage: false, remove: false });
const busy = ref(false);
const selection = ref([]);

const apps = computed(() => account.value.apps ?? []);
const limitLabel = computed(() => (account.value.limit === 'unlimited' ? t('unlimited') : account.value.limit));
const canCreate = computed(() => abilities.value.manage && account.value.allowed && account.value.hasRoom && props.initial.runtimes > 0);
const allSelected = computed(() => apps.value.length > 0 && selection.value.length === apps.value.length);

function apply(data) {
	if (data?.account) {
		account.value = data.account;
		selection.value = selection.value.filter((domain) => apps.value.some((app) => app.domain === domain));
	}
	if (data?.abilities) {
		abilities.value = data.abilities;
	}
}

async function refresh() {
	try {
		apply(await api.call('GET'));
	} catch {
		// the next round tries again
	}
}

function canRun(action) {
	return action === 'stop' ? abilities.value.remove : abilities.value.manage && account.value.allowed;
}

async function run(action, domains) {
	busy.value = true;
	let failed = 0;
	for (const domain of domains) {
		try {
			await api.call('POST', `/${encodeURIComponent(domain)}/${action}`);
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
	selection.value = event.target.checked ? apps.value.map((app) => app.domain) : [];
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
			<div class="ap-alert-body"><p>{{ t('Node.js is not included in your package.') }}</p></div>
		</div>

		<section class="ap-card ap-card-flush" aria-labelledby="node-apps-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="hexagon" />
					<h2 id="node-apps-title">{{ t('Node.js apps') }}</h2>
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
					<a v-if="canCreate" class="ap-btn ap-btn-primary ap-btn-sm" :href="pages.create"><ApIcon name="plus" />{{ t('Create app') }}</a>
					<button
						v-else-if="abilities.manage && account.allowed"
						type="button"
						class="ap-btn ap-btn-primary ap-btn-sm"
						disabled
						:title="!account.hasRoom ? t('Your package allows no more apps.') : t('Install a Node.js version first.')"
					>
						<ApIcon name="plus" />{{ t('Create app') }}
					</button>
				</div>
			</div>

			<div v-if="apps.length === 0" class="ap-empty">
				<div class="ap-empty-art"><ApIcon name="hexagon" /></div>
				<h3>{{ t('No Node.js app yet.') }}</h3>
				<p v-if="initial.runtimes === 0">
					{{ t('Install a Node.js version first.') }}
					<a v-if="pages.node" :href="pages.node">{{ t('Open the Node.js page') }}</a>
				</p>
				<p v-else>{{ t('An app runs a Node.js server for one of your web domains, with its own port, logs and restart on failure.') }}</p>
			</div>
			<div v-else class="ap-table-wrap">
				<table class="ap-table ap-table-cards">
					<caption class="sr-only">{{ t('Node.js apps') }}</caption>
					<thead>
						<tr>
							<th v-if="!embedded" scope="col" class="col-check">
								<label class="ap-check"><input type="checkbox" :checked="allSelected" :aria-label="t('Select all')" @change="toggleAll" /></label>
							</th>
							<th scope="col">{{ t('Domain') }}</th>
							<th scope="col">{{ t('Status') }}</th>
							<th scope="col">{{ t('Runtime') }}</th>
							<th scope="col" class="col-num">{{ t('CPU') }}</th>
							<th scope="col" class="col-num">{{ t('Memory') }}</th>
							<th scope="col" class="col-num">{{ t('Restarts') }}</th>
							<th scope="col" class="col-num">{{ t('Uptime') }}</th>
							<th scope="col" class="col-actions"><span class="sr-only">{{ t('Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="app in apps" :key="app.domain">
							<td v-if="!embedded" class="col-check">
								<label class="ap-check"><input v-model="selection" type="checkbox" :value="app.domain" :aria-label="t('Select %s', app.domain)" /></label>
							</td>
							<td :data-label="t('Domain')">
								<a :href="appUrl(pages.show, app.domain)" class="mono">{{ app.domain }}</a>
								<a :href="app.url" class="muted" target="_blank" rel="noopener" :aria-label="t('Open %s', app.url)">
									<ApIcon name="external" size="sm" />
								</a>
								<div class="muted mono">{{ app.name }} · :{{ app.port }}</div>
							</td>
							<td :data-label="t('Status')">
								<ApBadge :tone="appStatus(app).tone">{{ t(appStatus(app).label) }}</ApBadge>
								<div v-if="app.reason" class="muted">{{ app.reason }}</div>
							</td>
							<td :data-label="t('Runtime')" class="mono">{{ app.runtime ? `v${app.runtime}` : '—' }}</td>
							<td :data-label="t('CPU')" class="col-num mono">{{ app.online ? `${app.cpu}%` : '—' }}</td>
							<td :data-label="t('Memory')" class="col-num mono">{{ app.online ? `${app.memoryMb} ${t('MB')}` : '—' }}</td>
							<td :data-label="t('Restarts')" class="col-num mono">{{ app.restarts }}</td>
							<td :data-label="t('Uptime')" class="col-num mono">{{ app.online ? formatUptime(app.uptime) : '—' }}</td>
							<td class="col-actions">
								<div class="ap-row ap-row-end">
									<button
										v-if="!app.online && canRun('start') && !app.suspended"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Start')} ${app.domain}`"
										:title="t('Start')"
										:disabled="busy"
										@click="run('start', [app.domain])"
									>
										<ApIcon name="play" />
									</button>
									<button
										v-if="app.online && canRun('restart')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Restart')} ${app.domain}`"
										:title="t('Restart')"
										:disabled="busy"
										@click="run('restart', [app.domain])"
									>
										<ApIcon name="rotate" />
									</button>
									<button
										v-if="app.online && canRun('stop')"
										type="button"
										class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon"
										:aria-label="`${t('Stop')} ${app.domain}`"
										:title="t('Stop')"
										:disabled="busy"
										@click="run('stop', [app.domain])"
									>
										<ApIcon name="pause" />
									</button>
									<a class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon" :href="appUrl(pages.show, app.domain)" :aria-label="`${t('Open')} ${app.domain}`" :title="t('Open')">
										<ApIcon name="chevron-right" />
									</a>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="ap-card-foot">
				<span>{{ t('%d online · %d stopped · %d errored', account.totals.online, account.totals.stopped, account.totals.errored) }}</span>
				<span v-if="abilities.manage && !account.hasRoom">{{ t('Your package allows no more apps.') }}</span>
			</div>
		</section>
	</div>
</template>
