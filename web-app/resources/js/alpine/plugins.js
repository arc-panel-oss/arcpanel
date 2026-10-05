// Page-wide behaviour ported from the legacy app as Alpine plugins.

/** autoTrim: trim text fields on change (autoTrimInputs.js); opt out with data-no-trim. */
export function autoTrim() {
	document.addEventListener('change', (event) => {
		const field = event.target;
		if (
			field instanceof HTMLInputElement &&
			['text', 'email', 'url', 'search'].includes(field.type) &&
			!('noTrim' in field.dataset)
		) {
			field.value = field.value.trim();
		}
	});
}

/**
 * focusFirst: on a page with a [data-focus-first] form, focus the first field
 * with an error, else the first field (focusFirstInput.js), unless something
 * already has focus or carries autofocus.
 */
export function focusFirst() {
	document.addEventListener('alpine:initialized', () => {
		if (document.activeElement && document.activeElement !== document.body) {
			return;
		}
		if (document.querySelector('[autofocus]')) {
			return;
		}
		const form = document.querySelector('form[data-focus-first]');
		const field =
			form?.querySelector('[aria-invalid="true"]') ??
			form?.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
		field?.focus();
	});
}
