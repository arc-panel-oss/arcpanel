// Node.js page island (E13 spec §7.2), mounted on
// <x-ap-island name="node-manager"> by islands/loader.js.
import { createApp } from 'vue';
import NodeManager from './NodeManager.vue';

/**
 * @param {HTMLElement} element
 * @param {object} props  from NodeController::show()
 */
export function mount(element, props) {
	const app = createApp(NodeManager, { initial: props });
	app.mount(element);
	return app;
}
