// Docker apps island (E4 spec §7), mounted on <x-ap-island name="docker-apps">
// by islands/loader.js: My apps, the store, a catalog app with its install
// form, the page of one app or the engine page, as props.view says.
import { createApp } from 'vue';
import DockerAppList from './DockerAppList.vue';
import DockerAppPage from './DockerAppPage.vue';
import DockerDetail from './DockerDetail.vue';
import DockerEngine from './DockerEngine.vue';
import DockerStore from './DockerStore.vue';

const views = { list: DockerAppList, store: DockerStore, detail: DockerDetail, app: DockerAppPage, engine: DockerEngine };

/**
 * @param {HTMLElement} element
 * @param {object} props  from DockerAppPageController or DockerEngineController
 */
export function mount(element, props) {
	const app = createApp(views[props.view] ?? DockerAppList, { initial: props });
	app.mount(element);
	return app;
}
