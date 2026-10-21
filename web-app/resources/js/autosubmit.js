// Selects that submit their form as soon as another value is picked (owner
// pickers, filters, the language menu). One delegated listener instead of
// onchange attributes, which the Content Security Policy does not run
// (E39 W5 spec §9.2). The form's own button stays for pages without scripts.

/** @param {Document|HTMLElement} root */
export function initAutoSubmit(root) {
	root.addEventListener('change', (event) => {
		const field = event.target;
		if (!(field instanceof HTMLElement) || !field.hasAttribute('data-ap-autosubmit')) {
			return;
		}
		const form = /** @type {HTMLSelectElement} */ (field).form;
		if (form && typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		}
	});
}
