<script setup>
// Details of the selection (E3 spec §7.2): what the entry is and what can be
// done with it.
import { computed, ref, watch } from 'vue';
import ApIcon from '../shared/ApIcon.vue';
import { extension, formatSize, formatTime, iconFor, isEditable, isExtractable, joinPath, kindOf, modeString, previewKind } from './model.js';

const props = defineProps({
	entries: { type: Array, required: true },
	path: { type: String, required: true },
	manage: { type: Boolean, default: false },
	/** The open folder takes new files (drop hint) */
	canWrite: { type: Boolean, default: false },
	editMax: { type: Number, required: true },
	api: { type: Object, required: true },
	t: { type: Function, required: true },
});
const emit = defineEmits(['action']);

const entry = computed(() => (props.entries.length === 1 ? props.entries[0] : null));
const size = ref(null);
const sizing = ref(false);

watch(entry, () => {
	size.value = null;
});

const kindLabels = {
	dir: 'Folder',
	image: 'Image',
	video: 'Video',
	audio: 'Audio',
	pdf: 'PDF document',
	archive: 'Archive',
	code: 'Source code',
	text: 'Text',
	other: 'File',
};

async function measure() {
	sizing.value = true;
	try {
		size.value = (await props.api.size(joinPath(props.path, entry.value.name))).size;
	} catch (error) {
		props.api.fail(error);
	} finally {
		sizing.value = false;
	}
}

const total = computed(() => props.entries.reduce((sum, item) => sum + (item.dir ? 0 : item.size), 0));
</script>

<template>
	<aside class="ap-fm-aside" :aria-label="t('Selection details')">
		<template v-if="entry">
			<div :class="['ap-preview', { 'is-image': kindOf(entry) === 'image' }]">
				<ApIcon :name="iconFor(entry)" />
				<span v-if="!entry.dir && extension(entry.name)" class="ext">{{ extension(entry.name).toUpperCase() }}</span>
			</div>
			<div class="ap-cell-main">
				<b class="mono ap-break">{{ entry.name }}</b>
				<span>{{ t(kindLabels[kindOf(entry)]) }}<template v-if="!entry.dir"> · {{ formatSize(entry.size) }}</template></span>
			</div>
			<dl class="ap-dl">
				<dt>{{ t('Modified') }}</dt>
				<dd>{{ formatTime(entry.mtime) }}</dd>
				<dt>{{ t('Owner') }}</dt>
				<dd class="mono">{{ entry.owner }}:{{ entry.group }}</dd>
				<dt>{{ t('Permissions') }}</dt>
				<dd class="mono">{{ entry.mode }} · {{ modeString(entry.mode) }}</dd>
				<template v-if="entry.type === 'l'">
					<dt>{{ t('Link to') }}</dt>
					<dd class="mono ap-break">{{ entry.link }}</dd>
				</template>
				<template v-if="entry.dir">
					<dt>{{ t('Size') }}</dt>
					<dd>
						<span v-if="size !== null" class="mono">{{ formatSize(size) }}</span>
						<button v-else type="button" class="ap-btn ap-btn-ghost ap-btn-sm" :disabled="sizing" @click="measure">
							{{ sizing ? t('Counting…') : t('Calculate size') }}
						</button>
					</dd>
				</template>
			</dl>
			<div class="ap-stack">
				<button v-if="entry.dir" type="button" class="ap-btn ap-btn-primary" @click="emit('action', 'open')"><ApIcon name="folder" />{{ t('Open') }}</button>
				<button v-else-if="isEditable(entry, editMax)" type="button" class="ap-btn ap-btn-primary" @click="emit('action', 'edit')">
					<ApIcon name="edit" />{{ manage ? t('Edit') : t('View') }}
				</button>
				<button v-else-if="previewKind(entry)" type="button" class="ap-btn ap-btn-primary" @click="emit('action', 'preview')"><ApIcon name="eye" />{{ t('Preview') }}</button>
				<button type="button" class="ap-btn" @click="emit('action', 'download')"><ApIcon name="download" />{{ t('Download') }}</button>
				<button v-if="manage && isExtractable(entry.name) && !entry.dir" type="button" class="ap-btn" @click="emit('action', 'extract')">
					<ApIcon name="archive" />{{ t('Extract') }}
				</button>
				<button v-if="manage" type="button" class="ap-btn" @click="emit('action', 'permissions')"><ApIcon name="lock" />{{ t('Permissions') }}</button>
			</div>
		</template>
		<template v-else-if="entries.length > 1">
			<div class="ap-preview"><ApIcon name="layers" /></div>
			<div class="ap-cell-main">
				<b>{{ t('%d selected', entries.length) }}</b>
				<span>{{ formatSize(total) }}</span>
			</div>
			<div class="ap-stack">
				<button type="button" class="ap-btn" @click="emit('action', 'download')"><ApIcon name="download" />{{ t('Download as zip') }}</button>
				<button v-if="manage" type="button" class="ap-btn" @click="emit('action', 'permissions')"><ApIcon name="lock" />{{ t('Permissions') }}</button>
			</div>
		</template>
		<template v-else>
			<div class="ap-preview"><ApIcon name="folder" /></div>
			<p class="muted">{{ t('Select a file or folder to see its details.') }}</p>
		</template>
		<div v-if="manage && canWrite" class="ap-alert" role="note">
			<ApIcon name="info" size="sm" />
			<div class="ap-alert-body"><p>{{ t('Drop files anywhere on the list to upload them here.') }}</p></div>
		</div>
	</aside>
</template>
