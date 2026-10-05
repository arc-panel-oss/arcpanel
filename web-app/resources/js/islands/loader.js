// Vue island loader (docs/design/05 §5.2): <x-ap-island name="…"> renders
// <div data-island="name" data-props="{…}">; the chunk is imported on demand
// and exports mount(el, props). Islands register here as they are built
// (file-manager in E3, app-store in E4/E5 …).

/** @type {Record<string, () => Promise<{mount: (el: HTMLElement, props: object) => void}>>} */
export const islands = {};

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
