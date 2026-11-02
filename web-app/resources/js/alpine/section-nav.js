// apSectionNav: the in-page links of a long form (x-ap-section-nav). The link
// of the section at the top of the view is marked aria-current.

export default function apSectionNav() {
	return {
		current: '',

		init() {
			const links = [...this.$el.querySelectorAll('a[href^="#"]')];
			const sections = links.map((link) => document.getElementById(link.hash.slice(1))).filter(Boolean);
			this.current = sections[0]?.id ?? '';
			if (typeof IntersectionObserver === 'undefined' || sections.length === 0) {
				return;
			}
			const visible = new Set();
			this.observer = new IntersectionObserver(
				(entries) => {
					for (const entry of entries) {
						entry.isIntersecting ? visible.add(entry.target.id) : visible.delete(entry.target.id);
					}
					const first = sections.find((section) => visible.has(section.id));
					if (first) {
						this.current = first.id;
					}
				},
				// The band below the top bar
				{ rootMargin: '-72px 0px -60% 0px' },
			);
			for (const section of sections) {
				this.observer.observe(section);
			}
		},

		destroy() {
			this.observer?.disconnect();
		},

		isCurrent(id) {
			return this.current === id;
		},
	};
}
