// Toasts raised by scripts (palette and notification errors, bulk results).
// Server flash messages are rendered by <x-ap-toast> in the page; both end up
// in the same live region and use the apToast module.
import { __ } from './i18n.js';

const icons = { ok: 'i-check-circle', error: 'i-x-circle', info: 'i-info', warn: 'i-alert' };

/**
 * @param {'ok'|'error'|'info'|'warn'} type
 * @param {string} title
 * @param {string} [message]
 */
export function toast(type, title, message = '') {
	const region = document.querySelector('[data-toast-region]');
	if (!region) {
		return null;
	}

	const element = document.createElement('div');
	element.className = `ap-toast is-${type === 'error' ? 'danger' : type}`;
	element.setAttribute('role', type === 'error' ? 'alert' : 'status');
	element.setAttribute('x-data', `apToast({ sticky: ${type === 'error'} })`);
	element.innerHTML = `<svg class="ic" aria-hidden="true"><use href="#${icons[type] ?? icons.info}"></use></svg>
		<div class="ap-toast-body"><b></b><span></span></div>
		<button type="button" class="ap-toast-close" x-on:click="dismiss()"><svg class="ic ic-sm" aria-hidden="true"><use href="#i-x"></use></svg></button>`;
	element.querySelector('b').textContent = title;
	element.querySelector('.ap-toast-body span').textContent = message;
	element.querySelector('.ap-toast-close').setAttribute('aria-label', __('Dismiss'));
	region.append(element);

	return element;
}
