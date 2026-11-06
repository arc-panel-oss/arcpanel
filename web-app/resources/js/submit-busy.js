// Busy state of plain form posts. Most actions post a form and the server
// answers after its v-* commands have run, which takes seconds for some of
// them (adding a web domain, a mail or DNS domain, deleting a user). Until
// the next page arrives, the button that sent the form shows the spinner of
// x-ap-button's loading state and the form ignores a second submit, so a
// double click does not post twice.
//
// Left alone: submits that a script or the confirmation dialog prevented
// (they handle the action themselves, or submit again after the answer), GET
// forms (filters, search), forms with another target, and forms or buttons
// marked data-ap-no-busy (a post that answers with a download keeps the page).
// The button comes back when the page is shown again from the history cache,
// and after SUBMIT_BUSY_RESET_MS in case the answer never replaced the page.

export const SUBMIT_BUSY_RESET_MS = 30000;

/** @param {HTMLFormElement} form @param {HTMLElement|null} submitter */
function busyButton(form, submitter) {
	if (submitter) {
		return submitter;
	}
	return form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
}

/** @param {HTMLFormElement} form */
function release(form) {
	delete form.dataset.apBusy;
	for (const button of form.ownerDocument.querySelectorAll('[data-ap-busy-button]')) {
		if (button.form !== form && button.closest('form') !== form) {
			continue;
		}
		button.querySelector(':scope > .ap-spinner[data-ap-busy-spinner]')?.remove();
		button.removeAttribute('aria-busy');
		if (button.dataset.apBusyButton !== 'disabled') {
			button.removeAttribute('aria-disabled');
		}
		delete button.dataset.apBusyButton;
	}
}

/** @param {Document|HTMLElement} root @param {Window} [win] */
export function initSubmitBusy(root, win = globalThis) {
	const timers = new Map();

	// Registered on the root in the bubble phase, so the form's own listeners
	// (Alpine @submit.prevent) and the capture-phase confirmation run first
	root.addEventListener('submit', (event) => {
		const form = event.target;
		if (!(form instanceof HTMLFormElement)) {
			return;
		}
		if (form.dataset.apBusy === 'true') {
			event.preventDefault();
			return;
		}
		const submitter = /** @type {HTMLElement|null} */ (event.submitter ?? null);
		const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
		const target = submitter?.getAttribute('formtarget') || form.getAttribute('target') || '';
		if (
			event.defaultPrevented ||
			method !== 'post' ||
			(target !== '' && target !== '_self') ||
			form.hasAttribute('data-ap-no-busy') ||
			submitter?.hasAttribute('data-ap-no-busy')
		) {
			return;
		}

		form.dataset.apBusy = 'true';
		const button = busyButton(form, submitter);
		if (button && !button.hasAttribute('data-ap-busy-button')) {
			button.dataset.apBusyButton = button.getAttribute('aria-disabled') === 'true' ? 'disabled' : 'yes';
			button.setAttribute('aria-busy', 'true');
			button.setAttribute('aria-disabled', 'true');
			if (button.tagName === 'BUTTON') {
				const spinner = form.ownerDocument.createElement('span');
				spinner.className = 'ap-spinner';
				spinner.setAttribute('aria-hidden', 'true');
				spinner.dataset.apBusySpinner = '';
				button.prepend(spinner);
			}
		}
		clearTimeout(timers.get(form));
		timers.set(
			form,
			setTimeout(() => {
				timers.delete(form);
				release(form);
			}, SUBMIT_BUSY_RESET_MS),
		);
	});

	// Back or forward to a page whose form was posted: the page is live again
	win.addEventListener('pageshow', () => {
		for (const [form, timer] of timers) {
			clearTimeout(timer);
			release(form);
		}
		timers.clear();
	});
}
