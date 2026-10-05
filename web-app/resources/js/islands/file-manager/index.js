// File manager island (E3 spec §7), mounted on <x-ap-island name="file-manager">
// by islands/loader.js.
import { createApp } from 'vue';
import FileManager from './FileManager.vue';

/**
 * @param {HTMLElement} element
 * @param {object} props  from FilePageController
 */
export function mount(element, props) {
	const app = createApp(FileManager, { initial: props });
	app.mount(element);
	return app;
}
