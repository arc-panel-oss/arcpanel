// CodeMirror 6 for the file editor (E3 spec §7.6). Imported on the first edit
// only, so the file manager loads without it (its own chunk).
import { indentWithTab } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { php } from '@codemirror/lang-php';
import { sql } from '@codemirror/lang-sql';
import { yaml } from '@codemirror/lang-yaml';
import { HighlightStyle, indentUnit, StreamLanguage, syntaxHighlighting } from '@codemirror/language';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { properties } from '@codemirror/legacy-modes/mode/properties';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { openSearchPanel } from '@codemirror/search';
import { Compartment, EditorState } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { tags } from '@lezer/highlight';
import { basicSetup } from 'codemirror';
import { detectIndent } from './indent.js';

/** Apache configuration (.htaccess): comments, sections, directives */
const apache = {
	name: 'apache',
	startState: () => ({ start: true }),
	token(stream, state) {
		if (stream.sol()) {
			state.start = true;
		}
		if (stream.eatSpace()) {
			return null;
		}
		if (stream.peek() === '#' && state.start) {
			stream.skipToEnd();
			return 'comment';
		}
		if (stream.match(/^<\/?[A-Za-z]+/)) {
			state.start = false;
			return 'keyword';
		}
		if (stream.match(/^"(?:[^"\\]|\\.)*"?/)) {
			state.start = false;
			return 'string';
		}
		if (state.start && stream.match(/^[A-Za-z][\w-]*/)) {
			state.start = false;
			return 'keyword';
		}
		if (stream.match(/^%\{[^}]*\}?/) || stream.match(/^\$\d/)) {
			return 'variableName';
		}
		state.start = false;
		stream.next();
		return null;
	},
};

const LANGUAGES = {
	php: () => php(),
	javascript: () => javascript(),
	typescript: () => javascript({ typescript: true }),
	jsx: () => javascript({ jsx: true }),
	css: () => css(),
	html: () => html(),
	json: () => json(),
	yaml: () => yaml(),
	markdown: () => markdown(),
	sql: () => sql(),
	shell: () => StreamLanguage.define(shell),
	nginx: () => StreamLanguage.define(nginx),
	apache: () => StreamLanguage.define(apache),
	ini: () => StreamLanguage.define(properties),
};

/**
 * Colours of the code from the panel tokens (the tk-* colours of the Nocturne
 * prototype), on --ap-surface where the contrast check covers all of them.
 */
const highlight = HighlightStyle.define([
	{ tag: [tags.comment, tags.lineComment, tags.blockComment, tags.docComment], color: 'var(--ap-fg-subtle)', fontStyle: 'italic' },
	{ tag: [tags.keyword, tags.controlKeyword, tags.operatorKeyword, tags.definitionKeyword, tags.moduleKeyword, tags.modifier, tags.heading], color: 'var(--ap-accent)' },
	{ tag: [tags.string, tags.special(tags.string), tags.regexp, tags.character], color: 'var(--ap-ok)' },
	{ tag: [tags.number, tags.bool, tags.atom, tags.null, tags.attributeName, tags.variableName, tags.special(tags.variableName)], color: 'var(--ap-warn)' },
	{ tag: [tags.typeName, tags.className, tags.tagName, tags.namespace, tags.propertyName], color: 'var(--ap-info)' },
	{ tag: [tags.invalid], color: 'var(--ap-danger)' },
	{ tag: tags.strong, fontWeight: '600' },
	{ tag: tags.emphasis, fontStyle: 'italic' },
]);

/** Theme from the panel tokens, so both panel themes apply */
const theme = EditorView.theme({
	'&': { backgroundColor: 'var(--ap-surface)', color: 'var(--ap-fg)', height: '100%' },
	'.cm-content': { fontFamily: 'var(--ap-font-mono)', caretColor: 'var(--ap-accent)' },
	'.cm-gutters': { backgroundColor: 'var(--ap-surface)', color: 'var(--ap-fg-subtle)', borderRight: '1px solid var(--ap-border)' },
	'.cm-activeLine': { backgroundColor: 'color-mix(in oklab, var(--ap-accent) 7%, transparent)' },
	'.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--ap-fg)' },
	'&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection': { backgroundColor: 'var(--ap-accent-subtle)' },
	'.cm-panels': { backgroundColor: 'var(--ap-surface)', color: 'var(--ap-fg)' },
	'.cm-panels input, .cm-panels button': { fontFamily: 'inherit' },
	'.cm-searchMatch': { backgroundColor: 'var(--ap-warn-subtle)' },
	'.cm-tooltip': { backgroundColor: 'var(--ap-surface-raised)', border: '1px solid var(--ap-border)' },
});

/**
 * @param {HTMLElement} parent
 * @param {object} options
 * @param {string} options.doc
 * @param {string} options.language  key of LANGUAGES, or '' for plain text
 * @param {boolean} options.readOnly
 * @param {boolean} options.wrap
 * @param {() => void} options.onSave  Ctrl+S / Cmd+S
 * @param {(state: {dirty: boolean, line: number, column: number}) => void} options.onUpdate
 * @param {import('@codemirror/state').Extension[]} [options.extensions]  added after the panel's own
 */
export function createEditor(parent, options) {
	const wrapping = new Compartment();
	const indent = detectIndent(options.doc);
	const language = LANGUAGES[options.language]?.() ?? [];
	let saved = options.doc;

	const view = new EditorView({
		parent,
		state: EditorState.create({
			doc: options.doc,
			extensions: [
				// CRLF files keep CRLF when saved
				EditorState.lineSeparator.of(options.doc.includes('\r\n') ? '\r\n' : '\n'),
				basicSetup,
				theme,
				syntaxHighlighting(highlight),
				language,
				indentUnit.of(indent),
				EditorState.tabSize.of(indent === '\t' ? 4 : indent.length),
				EditorState.readOnly.of(options.readOnly),
				EditorView.editable.of(!options.readOnly),
				wrapping.of(options.wrap ? EditorView.lineWrapping : []),
				keymap.of([
					{
						key: 'Mod-s',
						preventDefault: true,
						run: () => {
							options.onSave();
							return true;
						},
					},
					indentWithTab,
				]),
				EditorView.updateListener.of((update) => {
					if (update.docChanged || update.selectionSet) {
						const head = update.state.selection.main.head;
						const line = update.state.doc.lineAt(head);
						options.onUpdate({ dirty: update.state.doc.toString() !== saved, line: line.number, column: head - line.from + 1 });
					}
				}),
				EditorView.contentAttributes.of({ 'aria-label': options.label ?? 'Editor' }),
				// Extensions of the caller (the line markers of the php.ini editor, E41)
				...(options.extensions ?? []),
			],
		}),
	});

	// The scroller can take the focus, so the keyboard can scroll read-only
	// content too (axe scrollable-region-focusable)
	view.scrollDOM.tabIndex = 0;
	view.scrollDOM.setAttribute('aria-label', options.label ?? 'Editor');

	return {
		view,
		value: () => view.state.doc.toString(),
		/** Mark the current content as saved */
		markSaved(content = view.state.doc.toString()) {
			saved = content;
			options.onUpdate({ dirty: view.state.doc.toString() !== saved, line: 1, column: 1 });
		},
		replace(content) {
			saved = content;
			view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: content } });
		},
		setWrap(on) {
			view.dispatch({ effects: wrapping.reconfigure(on ? EditorView.lineWrapping : []) });
		},
		search() {
			openSearchPanel(view);
		},
		focus() {
			view.focus();
		},
		destroy() {
			view.destroy();
		},
	};
}
