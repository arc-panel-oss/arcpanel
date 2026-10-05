<script setup>
// Preview of images, PDF, audio and video through a download ticket
// (inline), with previous and next among the images of the folder
// (E3 spec §7.5). Nothing passes through PHP.
import { computed } from 'vue';
import ApDialog from '../shared/ApDialog.vue';
import ApIcon from '../shared/ApIcon.vue';

const props = defineProps({
	open: { type: Boolean, default: false },
	entry: { type: Object, default: null },
	kind: { type: String, default: '' },
	url: { type: String, default: '' },
	hasPrevious: { type: Boolean, default: false },
	hasNext: { type: Boolean, default: false },
	t: { type: Function, required: true },
});
const emit = defineEmits(['close', 'previous', 'next', 'download']);

const src = computed(() => (props.url ? `${props.url}${props.url.includes('?') ? '&' : '?'}inline=1` : ''));

function onKeydown(event) {
	if (event.key === 'ArrowLeft' && props.hasPrevious) {
		emit('previous');
	} else if (event.key === 'ArrowRight' && props.hasNext) {
		emit('next');
	}
}
</script>

<template>
	<ApDialog :open="open" :title="entry?.name ?? ''" icon="eye" @close="emit('close')">
		<div class="ap-media-preview" tabindex="-1" @keydown="onKeydown">
			<p v-if="!src" class="muted">{{ t('Loading…') }}</p>
			<img v-else-if="kind === 'image'" :src="src" :alt="entry?.name" />
			<video v-else-if="kind === 'video'" :src="src" controls preload="metadata"></video>
			<audio v-else-if="kind === 'audio'" :src="src" controls preload="metadata"></audio>
			<iframe v-else-if="kind === 'pdf'" :src="src" :title="entry?.name"></iframe>
		</div>
		<template #footer>
			<button v-if="kind === 'image'" type="button" class="ap-btn ap-btn-ghost" :disabled="!hasPrevious" @click="emit('previous')"><ApIcon name="chevron-left" />{{ t('Previous') }}</button>
			<button v-if="kind === 'image'" type="button" class="ap-btn ap-btn-ghost" :disabled="!hasNext" @click="emit('next')">{{ t('Next') }}<ApIcon name="chevron-right" /></button>
			<button type="button" class="ap-btn" @click="emit('download')"><ApIcon name="download" />{{ t('Download') }}</button>
			<button type="button" class="ap-btn ap-btn-primary" data-autofocus @click="emit('close')">{{ t('Close') }}</button>
		</template>
	</ApDialog>
</template>
