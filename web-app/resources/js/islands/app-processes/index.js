// App processes island (E42 spec §9), mounted on <x-ap-island name="app-processes">
// by islands/loader.js: the list, the create wizard or the page of one
// process, as props.view says.
import { createApp } from 'vue';
import ProcessCreate from './ProcessCreate.vue';
import ProcessList from './ProcessList.vue';
import ProcessPage from './ProcessPage.vue';

const views = { list: ProcessList, create: ProcessCreate, show: ProcessPage };

/**
 * @param {HTMLElement} element
 * @param {object} props  from AppProcessPageController
 */
export function mount(element, props) {
	const app = createApp(views[props.view] ?? ProcessList, { initial: props });
	app.mount(element);
	return app;
}
