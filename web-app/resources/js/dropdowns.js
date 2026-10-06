// Dropdowns from <details class="ap-dropdown"> (row actions, column chooser,
// user switcher): one open at a time, closed by a click outside or Escape.
// A menu inside a scrolling table is placed with fixed coordinates, so the
// table does not clip it, and opens upwards when the viewport has no room
// below. Without this script the dropdowns still open and close.

const GAP = 4;
const MARGIN = 8;

/** @param {HTMLDetailsElement} details */
function menuOf(details) {
	return details.querySelector(':scope > .ap-menu');
}

/** @param {HTMLDetailsElement} details */
function reset(details) {
	const menu = menuOf(details);
	if (menu) {
		menu.style.removeProperty('position');
		menu.style.removeProperty('top');
		menu.style.removeProperty('left');
		menu.style.removeProperty('right');
		menu.style.removeProperty('inset-inline-end');
	}
	details.classList.remove('is-up');
}

/** @param {HTMLDetailsElement} details */
export function placeMenu(details) {
	const menu = menuOf(details);
	const summary = details.querySelector(':scope > summary');
	if (!menu || !summary) {
		return;
	}
	const anchor = summary.getBoundingClientRect();
	const height = menu.offsetHeight;
	const width = menu.offsetWidth;
	const below = window.innerHeight - anchor.bottom;
	const up = below < height + GAP + MARGIN && anchor.top > below;

	if (details.closest('.ap-table-wrap')) {
		const rtl = getComputedStyle(details).direction === 'rtl';
		const left = rtl ? anchor.left : anchor.right - width;
		const maxLeft = Math.max(MARGIN, window.innerWidth - width - MARGIN);
		menu.style.position = 'fixed';
		menu.style.insetInlineEnd = 'auto';
		menu.style.right = 'auto';
		menu.style.left = `${Math.min(Math.max(left, MARGIN), maxLeft)}px`;
		menu.style.top = `${up ? anchor.top - GAP - height : anchor.bottom + GAP}px`;
	} else {
		details.classList.toggle('is-up', up);
	}
}

/** @param {Document} doc */
function openDropdowns(doc) {
	return [...doc.querySelectorAll('details.ap-dropdown[open]')];
}

/** @param {Document} doc */
export function initDropdowns(doc = document) {
	doc.addEventListener(
		'toggle',
		(event) => {
			const details = event.target;
			if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('ap-dropdown')) {
				return;
			}
			if (!details.open) {
				reset(details);
				return;
			}
			for (const other of openDropdowns(doc)) {
				if (other !== details && !other.contains(details)) {
					other.open = false;
				}
			}
			placeMenu(details);
		},
		true,
	);

	doc.addEventListener('click', (event) => {
		for (const details of openDropdowns(doc)) {
			if (!details.contains(event.target)) {
				details.open = false;
			}
		}
	});

	doc.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') {
			return;
		}
		for (const details of openDropdowns(doc)) {
			const focusInside = details.contains(doc.activeElement);
			details.open = false;
			if (focusInside) {
				details.querySelector(':scope > summary')?.focus();
			}
		}
	});

	// A fixed menu would drift from its row: close it when the page scrolls.
	const closeFixed = (event) => {
		for (const details of openDropdowns(doc)) {
			const menu = menuOf(details);
			const inside = event.target instanceof Node && menu?.contains(event.target);
			if (menu?.style.position === 'fixed' && !inside) {
				details.open = false;
			}
		}
	};
	doc.defaultView?.addEventListener('scroll', closeFixed, true);
	doc.defaultView?.addEventListener('resize', closeFixed);
}
