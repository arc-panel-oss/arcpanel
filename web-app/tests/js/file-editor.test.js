import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import FileEditor from '../../resources/js/islands/file-manager/FileEditor.vue';
import { translator } from '../../resources/js/islands/shared/strings.js';

// CodeMirror needs a real layout; the editor talks to this stand-in instead.
let content = '';
let hooks = {};
vi.mock('../../resources/js/islands/file-manager/editor/codemirror.js', () => ({
	createEditor: (_parent, options) => {
		content = options.doc;
		hooks = options;
		return {
			value: () => content,
			markSaved: () => options.onUpdate({ dirty: false, line: 1, column: 1 }),
			setWrap: () => {},
			search: () => {},
			focus: () => {},
			destroy: () => {},
		};
	},
}));

function type(text) {
	content = text;
	hooks.onUpdate({ dirty: true, line: 1, column: text.length + 1 });
}

function fakeApi(saves) {
	return {
		read: vi.fn(async () => ({ content: '<?php', hash: 'a'.repeat(64), size: 5, mime: 'text/x-php', utf8: true })),
		save: vi.fn(async (...args) => saves(...args)),
		toast: vi.fn(),
		fail: vi.fn(),
	};
}

afterEach(() => {
	cleanup();
	content = '';
	hooks = {};
});

describe('file editor', () => {
	it('saves with the hash read and marks the file saved', async () => {
		const api = fakeApi(async () => ({ hash: 'b'.repeat(64), size: 9, mtime: 2 }));
		render(FileEditor, { props: { api, path: 'web/index.php', manage: true, t: translator() } });
		await waitFor(() => expect(api.read).toHaveBeenCalledWith('web/index.php'));
		await waitFor(() => expect(hooks.onSave).toBeTypeOf('function'));

		type('<?php echo 1;');
		await fireEvent.click(await screen.findByRole('button', { name: /Save/ }));
		await waitFor(() => expect(api.save).toHaveBeenCalledWith('web/index.php', '<?php echo 1;', 'a'.repeat(64)));
		expect(api.toast).toHaveBeenCalledWith('ok', 'index.php has been saved.');
	});

	it('asks what to do when the file changed on disk, and overwrites with the new hash', async () => {
		let first = true;
		const api = fakeApi(async () => {
			if (first) {
				first = false;
				const error = new Error('changed');
				error.status = 409;
				throw error;
			}
			return { hash: 'c'.repeat(64), size: 3, mtime: 3 };
		});
		render(FileEditor, { props: { api, path: 'a.txt', manage: true, t: translator() } });
		await waitFor(() => expect(hooks.onSave).toBeTypeOf('function'));
		type('new');
		hooks.onSave();

		expect(await screen.findByText('The file changed on disk')).toBeTruthy();
		api.read.mockResolvedValueOnce({ content: 'theirs', hash: 'd'.repeat(64), size: 6, mime: 'text/plain', utf8: true });
		await fireEvent.click(screen.getByRole('button', { name: 'Overwrite' }));
		await waitFor(() => expect(api.save).toHaveBeenLastCalledWith('a.txt', 'new', 'd'.repeat(64)));
	});

	it('opens files that are not UTF-8 read-only and offers no save', async () => {
		const api = fakeApi(async () => ({}));
		api.read.mockResolvedValue({ content: '??', hash: 'e'.repeat(64), size: 2, mime: 'text/plain', utf8: false });
		render(FileEditor, { props: { api, path: 'latin1.txt', manage: true, t: translator() } });
		expect(await screen.findByText(/not UTF-8 text/)).toBeTruthy();
		expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();
		expect(hooks.readOnly).toBe(true);
	});
});
