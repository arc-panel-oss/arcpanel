import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from '../../resources/js/toast.js';
import { mount, unmount } from './helpers.js';

beforeEach(() => {
	vi.useFakeTimers();
});

afterEach(() => {
	unmount();
	vi.useRealTimers();
	delete window.__ap;
});

describe('apToast', () => {
	it('leaves after 6 seconds', () => {
		mount(`<div id="t" x-data="apToast()">Saved</div>`);
		vi.advanceTimersByTime(5999);
		expect(document.getElementById('t')).not.toBeNull();
		vi.advanceTimersByTime(1);
		expect(document.getElementById('t')).toBeNull();
	});

	it('honours a custom timeout', () => {
		mount(`<div id="t" x-data="apToast({ timeout: 1000 })">Saved</div>`);
		vi.advanceTimersByTime(1000);
		expect(document.getElementById('t')).toBeNull();
	});

	it('keeps sticky (error) toasts until dismissed', () => {
		mount(`<div id="t" x-data="apToast({ sticky: true })"><button id="x" x-on:click="dismiss()">x</button></div>`);
		vi.advanceTimersByTime(60000);
		expect(document.getElementById('t')).not.toBeNull();
		document.getElementById('x').click();
		expect(document.getElementById('t')).toBeNull();
	});

	it('pauses while hovered or focused', () => {
		mount(`<div id="t" x-data="apToast()"><button id="x">x</button></div>`);
		const el = document.getElementById('t');
		vi.advanceTimersByTime(3000);
		el.dispatchEvent(new Event('mouseenter'));
		vi.advanceTimersByTime(10000);
		expect(document.getElementById('t')).not.toBeNull();

		el.dispatchEvent(new Event('mouseleave'));
		vi.advanceTimersByTime(5999);
		expect(document.getElementById('t')).not.toBeNull();

		document.getElementById('x').dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
		vi.advanceTimersByTime(10000);
		expect(document.getElementById('t')).not.toBeNull();

		document.getElementById('x').dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
		vi.advanceTimersByTime(6000);
		expect(document.getElementById('t')).toBeNull();
	});
});

describe('toast()', () => {
	it('adds a toast to the live region', () => {
		document.body.innerHTML = '<div data-toast-region></div>';
		window.__ap = { i18n: { Dismiss: 'Đóng' } };

		const el = toast('ok', 'Domain added', 'example.com is live');
		expect(el.parentElement).toBe(document.querySelector('[data-toast-region]'));
		expect(el.className).toBe('ap-toast is-ok');
		expect(el.getAttribute('role')).toBe('status');
		expect(el.getAttribute('x-data')).toBe('apToast({ sticky: false })');
		expect(el.querySelector('b').textContent).toBe('Domain added');
		expect(el.querySelector('.ap-toast-body span').textContent).toBe('example.com is live');
		expect(el.querySelector('use').getAttribute('href')).toBe('#i-check-circle');
		expect(el.querySelector('.ap-toast-close').getAttribute('aria-label')).toBe('Đóng');
	});

	it('makes errors sticky alerts and escapes text', () => {
		document.body.innerHTML = '<div data-toast-region></div>';
		const el = toast('error', '<img src=x>');
		expect(el.className).toBe('ap-toast is-danger');
		expect(el.getAttribute('role')).toBe('alert');
		expect(el.getAttribute('x-data')).toBe('apToast({ sticky: true })');
		expect(el.querySelector('b').textContent).toBe('<img src=x>');
		expect(el.querySelector('img')).toBeNull();
		expect(el.querySelector('use').getAttribute('href')).toBe('#i-x-circle');
	});

	it('does nothing without a region', () => {
		document.body.innerHTML = '';
		expect(toast('info', 'Hello')).toBeNull();
	});
});
