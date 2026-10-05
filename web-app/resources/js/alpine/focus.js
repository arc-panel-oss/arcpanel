// Focus helpers shared by the dialog-like modules (modal, confirm, palette).

const focusable =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** @param {HTMLElement} container */
export function focusableIn(container) {
	return [...container.querySelectorAll(focusable)].filter((el) => !el.closest('[hidden]'));
}

/** Focus [autofocus] or the first focusable element. @param {HTMLElement} container */
export function focusFirst(container) {
	const target = container.querySelector('[autofocus]') ?? focusableIn(container)[0] ?? container;
	target.focus?.();
}

/**
 * Keep Tab inside the container.
 *
 * @param {HTMLElement} container
 * @param {KeyboardEvent} event
 */
export function trapFocus(container, event) {
	if (event.key !== 'Tab') {
		return;
	}
	const items = focusableIn(container);
	if (items.length === 0) {
		event.preventDefault();
		return;
	}
	const first = items[0];
	const last = items[items.length - 1];
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault();
		first.focus();
	}
}
