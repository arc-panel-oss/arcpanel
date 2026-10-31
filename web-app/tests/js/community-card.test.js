import { afterEach, describe, expect, it } from 'vitest';
import { PREFERENCES_COOKIE, readPreferences, writePreferences } from '../../resources/js/alpine/user-menu.js';
import { mount, tick, unmount } from './helpers.js';

const markup = `
<section id="card" x-data="apCommunityCard">
	<button id="hide" x-on:click="hide()">Hide</button>
</section>`;

afterEach(() => {
	unmount();
	// biome-ignore lint/suspicious/noDocumentCookie: test cleanup
	document.cookie = `${PREFERENCES_COOKIE}=; path=/; max-age=0`;
});

describe('apCommunityCard', () => {
	it('stores the hidden card in arc_ui, keeps the other preferences and removes the card', async () => {
		writePreferences({ theme: 'light', sidebar: 'rail' });
		mount(markup);

		document.getElementById('hide').click();
		await tick();

		expect(readPreferences()).toEqual({ theme: 'light', sidebar: 'rail', community: 'hidden' });
		expect(document.getElementById('card')).toBeNull();
	});
});
