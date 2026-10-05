<script setup>
// The store (E4 spec §7): the catalog as cards with search and category
// chips; an app opens its detail page with the install form.
import { computed, ref } from 'vue';
import ApBadge from '../shared/ApBadge.vue';
import ApIcon from '../shared/ApIcon.vue';
import { translator } from '../shared/strings.js';
import { pageUrl } from './api.js';
import { categoriesOf, filterCatalog } from './model.js';

const props = defineProps({
	initial: { type: Object, required: true },
});

const t = translator(props.initial.strings);
const pages = props.initial.pages;
const catalog = props.initial.catalog ?? [];
const installed = props.initial.installed ?? {};
const abilities = props.initial.abilities ?? {};

const query = ref('');
const category = ref('');
const categories = categoriesOf(catalog);
const apps = computed(() => filterCatalog(catalog, query.value, category.value));
</script>

<template>
	<div class="ap-stack">
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

		<section class="ap-card" aria-labelledby="docker-store-title">
			<div class="ap-card-head">
				<div class="ap-card-title">
					<ApIcon name="container" />
					<h2 id="docker-store-title">{{ t('Store') }}</h2>
				</div>
				<div class="ap-actions">
					<a class="ap-btn ap-btn-ghost ap-btn-sm" :href="pages.list"><ApIcon name="list" />{{ t('Docker apps') }}</a>
				</div>
			</div>
			<div class="ap-picker-tools">
				<div class="ap-input-affix">
					<ApIcon name="search" size="sm" />
					<input v-model="query" class="ap-input" type="search" :aria-label="t('Search apps')" :placeholder="t('Search apps')" />
				</div>
				<div v-if="categories.length > 1" class="ap-row ap-picker-chips" role="group" :aria-label="t('Category')">
					<button type="button" class="ap-chip" :class="{ 'is-on': category === '' }" :aria-pressed="category === '' ? 'true' : 'false'" @click="category = ''">
						{{ t('All') }}
					</button>
					<button
						v-for="name in categories"
						:key="name"
						type="button"
						class="ap-chip"
						:class="{ 'is-on': category === name }"
						:aria-pressed="category === name ? 'true' : 'false'"
						@click="category = name"
					>
						{{ t(name) }}
					</button>
				</div>
			</div>
			<p v-if="apps.length === 0" class="muted">{{ t('No app in the store matches.') }}</p>
			<ul v-else class="ap-picker ap-store" role="list">
				<li v-for="app in apps" :key="app.slug">
					<a class="ap-pick" :href="pageUrl(pages.detail, app.slug)" :data-app="app.slug">
						<span class="ap-pick-logo">
							<img v-if="app.icon" :src="app.icon" alt="" />
							<template v-else>{{ app.name.slice(0, 2) }}</template>
						</span>
						<b>{{ app.name }}</b>
						<span>{{ app.summary }}</span>
						<span class="ap-pick-foot">
							<ApBadge>{{ t(app.category) }}</ApBadge>
							<ApBadge v-if="installed[app.slug]" tone="ok">{{ t('Installed') }}</ApBadge>
							<ApBadge v-if="app.adminOnly" tone="warn">{{ t('Administrators only') }}</ApBadge>
							<small class="mono">{{ app.version }}</small>
						</span>
					</a>
				</li>
			</ul>
		</section>
	</div>
</template>
