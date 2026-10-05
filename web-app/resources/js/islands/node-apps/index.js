// Node.js apps island (E14 spec §7), mounted on <x-ap-island name="node-apps">
// by islands/loader.js: the list, the create wizard or the page of one app,
// as props.view says.
import { createApp } from 'vue';
import NodeAppCreate from './NodeAppCreate.vue';
import NodeAppList from './NodeAppList.vue';
import NodeAppPage from './NodeAppPage.vue';

const views = { list: NodeAppList, create: NodeAppCreate, show: NodeAppPage };

/**
 * @param {HTMLElement} element
 * @param {object} props  from NodeAppPageController
 */
export function mount(element, props) {
	const app = createApp(views[props.view] ?? NodeAppList, { initial: props });
	app.mount(element);
	return app;
}
