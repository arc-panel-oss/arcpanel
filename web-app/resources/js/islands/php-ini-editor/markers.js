// Line markers of the php.ini editor (E41 spec §8): a CodeMirror state field
// of line decorations that the findings of the last check set. Imported with
// CodeMirror, in its own chunk.
import { StateEffect, StateField } from '@codemirror/state';
import { Decoration, EditorView } from '@codemirror/view';
import { markerLines } from './findings.js';

const setMarkers = StateEffect.define();

const field = StateField.define({
	create: () => Decoration.none,
	update(markers, transaction) {
		let next = markers.map(transaction.changes);
		for (const effect of transaction.effects) {
			if (effect.is(setMarkers)) {
				next = effect.value;
			}
		}
		return next;
	},
	provide: (f) => EditorView.decorations.from(f),
});

/** The extension that holds the markers. */
export function markersExtension() {
	return [field];
}

/**
 * Mark the lines of the findings; an empty list clears them.
 *
 * @param {EditorView} view
 * @param {{line: number, level: string}[]} findings
 */
export function showMarkers(view, findings) {
	const doc = view.state.doc;
	const ranges = [];
	for (const [line, level] of [...markerLines(findings, doc.lines)].sort((a, b) => a[0] - b[0])) {
		ranges.push(Decoration.line({ class: `ap-ini-marker is-${level}` }).range(doc.line(line).from));
	}
	view.dispatch({ effects: setMarkers.of(Decoration.set(ranges)) });
}

/**
 * Move the cursor to a line and show it.
 *
 * @param {EditorView} view
 * @param {number} line
 */
export function goToLine(view, line) {
	const target = view.state.doc.line(Math.min(Math.max(line, 1), view.state.doc.lines));
	view.dispatch({ selection: { anchor: target.from }, scrollIntoView: true });
	view.focus();
}
