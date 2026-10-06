// Config editor island (E7 batch 3 spec §9.2), mounted on
// <x-ap-island name="service-editor"> by islands/loader.js.
import { createApp } from 'vue';
import ServiceEditor from './ServiceEditor.vue';

/**
 * @param {HTMLElement} element
 * @param {object} props  from ServiceConfigController::show()
 */
export function mount(element, props) {
	const app = createApp(ServiceEditor, { initial: props });
	app.mount(element);
	return app;
}
