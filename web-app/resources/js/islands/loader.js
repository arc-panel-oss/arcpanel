// Vue island loader (docs/design/05 §5.2): <x-ap-island name="…"> renders
// <div data-island="name" data-props="{…}">; the chunk is imported on demand
// and exports mount(el, props). Islands register here as they are built
// (node-manager in E13, node-apps in E14, file-manager in E3, docker-apps in E4 …).

/** @type {Record<string, () => Promise<{mount: (el: HTMLElement, props: object) => void}>>} */
export const islands = {
	'node-manager': () => import('./node-manager/index.js'),
	'node-apps': () => import('./node-apps/index.js'),
	'file-manager': () => import('./file-manager/index.js'),
	'docker-apps': () => import('./docker-apps/index.js'),
	'service-editor': () => import('./service-editor/index.js'),
};

/** @param {ParentNode} root */
export async function loadIslands(root, registry = islands) {
	const mounted = [];
	for (const element of root.querySelectorAll('[data-island]:not([data-island-mounted])')) {
		const name = element.dataset.island;
		const load = registry[name];
		if (!load) {
			console.warn(`Unknown island "${name}"`);
			continue;
		}
		let props = {};
		try {
			props = JSON.parse(element.dataset.props ?? '{}');
		} catch {
			console.warn(`Island "${name}" has invalid props`);
		}
		const module = await load();
		module.mount(element, props);
		element.dataset.islandMounted = 'yes';
		mounted.push(name);
	}
	return mounted;
}
