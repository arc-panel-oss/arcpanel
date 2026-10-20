// ArcPanel — Nocturne template behaviour (static prototypes only).
// In the panel these become Alpine components (docs/design/05 §5.1).
(() => {
	const root = document.documentElement;
	root.classList.add("has-js");

	const store = {
		get(key) {
			try {
				return localStorage.getItem(key);
			} catch {
				return null;
			}
		},
		set(key, value) {
			try {
				localStorage.setItem(key, value);
			} catch {
				/* storage unavailable */
			}
		},
	};

	// Theme: nocturne (dark) or light, remembered per browser.
	const saved = store.get("ap-theme");
	if (saved) root.dataset.theme = saved;
	for (const btn of document.querySelectorAll("[data-theme-toggle]")) {
		btn.addEventListener("click", () => {
			const next = root.dataset.theme === "light" ? "nocturne" : "light";
			root.dataset.theme = next;
			store.set("ap-theme", next);
		});
	}

	// Off-canvas sidebar on phones.
	const sidebar = document.querySelector(".ap-sidebar");
	const setSidebar = (open) => {
		if (!sidebar) return;
		sidebar.classList.toggle("is-open", open);
		for (const t of document.querySelectorAll("[data-sidebar-open]")) t.setAttribute("aria-expanded", String(open));
		if (open) sidebar.querySelector("a, button")?.focus();
	};
	for (const t of document.querySelectorAll("[data-sidebar-open]")) t.addEventListener("click", () => setSidebar(true));
	for (const t of document.querySelectorAll("[data-sidebar-close]")) t.addEventListener("click", () => setSidebar(false));

	// Overlays (modal, palette): [data-open="id"], [data-close], Esc, backdrop click.
	let lastFocus = null;
	const open = (id) => {
		const el = document.getElementById(id);
		if (!el) return;
		lastFocus = document.activeElement;
		el.hidden = false;
		el.querySelector("input, button, a")?.focus();
	};
	const close = (el) => {
		el.hidden = true;
		lastFocus?.focus();
	};
	for (const t of document.querySelectorAll("[data-open]")) {
		t.addEventListener("click", () => {
			// A dialog that opens the next step (pre-flight → progress) hands over its focus return.
			const parent = t.closest(".ap-overlay");
			const back = parent ? lastFocus : null;
			if (parent) parent.hidden = true;
			open(t.dataset.open);
			if (back) lastFocus = back;
		});
	}
	for (const ov of document.querySelectorAll(".ap-overlay")) {
		ov.addEventListener("click", (e) => {
			if (e.target === ov || e.target.closest("[data-close]")) close(ov);
		});
	}
	document.addEventListener("keydown", (e) => {
		if (e.key === "Escape") {
			for (const ov of document.querySelectorAll(".ap-overlay:not([hidden])")) close(ov);
			setSidebar(false);
		}
		if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k" && document.getElementById("palette")) {
			e.preventDefault();
			open("palette");
		}
	});

	// Tabs and segmented controls: one selected per group.
	for (const list of document.querySelectorAll('[role="tablist"]')) {
		list.addEventListener("click", (e) => {
			const tab = e.target.closest('[role="tab"]');
			if (!tab) return;
			for (const t of list.querySelectorAll('[role="tab"]')) t.setAttribute("aria-selected", String(t === tab));
		});
	}
	for (const seg of document.querySelectorAll(".ap-segment")) {
		seg.addEventListener("click", (e) => {
			const b = e.target.closest("button");
			if (!b) return;
			for (const x of seg.querySelectorAll("button")) x.setAttribute("aria-pressed", String(x === b));
		});
	}

	// Password reveal.
	for (const b of document.querySelectorAll("[data-reveal]")) {
		b.addEventListener("click", () => {
			const input = document.getElementById(b.dataset.reveal);
			if (!input) return;
			const show = input.type === "password";
			input.type = show ? "text" : "password";
			b.setAttribute("aria-pressed", String(show));
			b.setAttribute("aria-label", show ? "Hide password" : "Show password");
		});
	}

	// Confirmations: a [data-requires="id"] button stays disabled until every box in #id is ticked.
	for (const btn of document.querySelectorAll("[data-requires]")) {
		const group = document.getElementById(btn.dataset.requires);
		if (!group) continue;
		const boxes = [...group.querySelectorAll("input[type=checkbox]")];
		const count = document.querySelector(`[data-confirm-count="${group.id}"]`);
		const sync = () => {
			const n = boxes.filter((b) => b.checked).length;
			btn.disabled = n < boxes.length;
			if (count) count.textContent = `${n} of ${boxes.length} confirmed`;
		};
		for (const b of boxes) b.addEventListener("change", sync);
		sync();
	}

	// Toast dismiss.
	for (const b of document.querySelectorAll(".ap-toast-close")) {
		b.addEventListener("click", () => b.closest(".ap-toast")?.remove());
	}

	// Table selection drives the bulk bar.
	for (const table of document.querySelectorAll("[data-selectable]")) {
		const all = table.querySelector("thead input[type=checkbox]");
		const rows = [...table.querySelectorAll("tbody input[type=checkbox]")];
		const bar = document.getElementById(table.dataset.selectable);
		const sync = () => {
			const n = rows.filter((r) => r.checked).length;
			for (const r of rows) r.closest("tr").classList.toggle("is-selected", r.checked);
			if (all) {
				all.checked = n === rows.length;
				all.indeterminate = n > 0 && n < rows.length;
			}
			if (bar) {
				bar.hidden = n === 0;
				const count = bar.querySelector("[data-count]");
				if (count) count.textContent = `${n} selected`;
			}
		};
		all?.addEventListener("change", () => {
			for (const r of rows) r.checked = all.checked;
			sync();
		});
		for (const r of rows) r.addEventListener("change", sync);
		sync();
	}
})();
