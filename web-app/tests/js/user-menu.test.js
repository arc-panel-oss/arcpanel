import { afterEach, describe, expect, it } from 'vitest';
import { PREFERENCES_COOKIE, readPreferences, writePreferences } from '../../resources/js/alpine/user-menu.js';
import { data, mount, tick, unmount } from './helpers.js';

const markup = (options = "theme: 'nocturne', density: 'comfortable'") => `
<div id="menu" x-data="apUserMenu({ ${options} })">
	<button id="button" x-ref="button" x-on:click="toggle()">Account</button>
	<div x-show="open"><button id="light" x-on:click="setTheme('light')">Light</button></div>
</div>`;

function cookie() {
	const entry = document.cookie.split('; ').find((part) => part.startsWith(`${PREFERENCES_COOKIE}=`));
	return entry ? JSON.parse(decodeURIComponent(entry.slice(PREFERENCES_COOKIE.length + 1))) : null;
}

afterEach(() => {
	unmount();
	// biome-ignore lint/suspicious/noDocumentCookie: test cleanup
	document.cookie = `${PREFERENCES_COOKIE}=; path=/; max-age=0`;
	delete document.documentElement.dataset.theme;
	delete document.documentElement.dataset.density;
});

const menu = () => data(document.getElementById('menu'));

describe('apUserMenu', () => {
	it('applies the theme at once and stores it in arc_ui', async () => {
		mount(markup());
		document.getElementById('button').click();
		await tick();
		document.getElementById('light').click();

		expect(document.documentElement.dataset.theme).toBe('light');
		expect(menu().theme).toBe('light');
		expect(cookie()).toEqual({ theme: 'light', density: 'comfortable' });
	});

	it('applies the density and keeps the theme in the cookie', () => {
		mount(markup());
		menu().setDensity('compact');
		expect(document.documentElement.dataset.density).toBe('compact');
		expect(cookie()).toEqual({ theme: 'nocturne', density: 'compact' });
	});

	it('rejects unknown values', () => {
		mount(markup());
		menu().setTheme('purple');
		menu().setDensity('tiny');
		expect(menu().theme).toBe('nocturne');
		expect(menu().density).toBe('comfortable');
		expect(document.documentElement.dataset.theme).toBeUndefined();
		expect(cookie()).toBeNull();
	});

	it('ignores theme changes when the policy forbids them', () => {
		mount(markup("theme: 'light', density: 'comfortable', canChangeTheme: false"));
		menu().setTheme('nocturne');
		expect(menu().theme).toBe('light');
		expect(cookie()).toBeNull();

		menu().setDensity('compact');
		expect(cookie()).toEqual({ theme: 'light', density: 'compact' });
	});

	it('opens, closes and returns focus to its button', () => {
		mount(markup());
		menu().toggle();
		expect(menu().open).toBe(true);
		document.getElementById('light').focus();
		menu().close(true);
		expect(menu().open).toBe(false);
		expect(document.activeElement.id).toBe('button');

		menu().toggle();
		document.getElementById('light').focus();
		menu().close();
		expect(document.activeElement.id).toBe('light');
	});
});

describe('writePreferences', () => {
	it('writes a JSON cookie for the whole site', () => {
		writePreferences({ theme: 'auto', density: 'compact' });
		expect(cookie()).toEqual({ theme: 'auto', density: 'compact' });
	});

	it('keeps the keys it is not given', () => {
		writePreferences({ sidebar: 'rail' });
		writePreferences({ theme: 'light', density: 'comfortable' });
		expect(cookie()).toEqual({ sidebar: 'rail', theme: 'light', density: 'comfortable' });
		expect(readPreferences()).toEqual({ sidebar: 'rail', theme: 'light', density: 'comfortable' });
	});

	it('starts over from an unreadable cookie', () => {
		// biome-ignore lint/suspicious/noDocumentCookie: test setup
		document.cookie = `${PREFERENCES_COOKIE}=not-json; path=/`;
		expect(readPreferences()).toEqual({});
		writePreferences({ density: 'compact' });
		expect(cookie()).toEqual({ density: 'compact' });
	});
});
