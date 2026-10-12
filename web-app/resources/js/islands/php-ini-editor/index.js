// php.ini editor island (E41 spec §8), mounted on
// <x-ap-island name="php-ini-editor"> by islands/loader.js.
import { createApp } from 'vue';
import PhpIniEditor from './PhpIniEditor.vue';

/**
 * @param {HTMLElement} element
 * @param {object} props  from PhpController::show()
 */
export function mount(element, props) {
	const app = createApp(PhpIniEditor, { initial: props });
	app.mount(element);
	return app;
}
