// apSectionNav: the in-page links of a long form (x-ap-section-nav). The link
// of the section under the top bar is marked aria-current: the last section
// whose top has passed the line below the bar, or the last one at the bottom
// of the page.

const LINE = 120;

export default function apSectionNav() {
	return {
		current: '',
		sections: [],

		init() {
			this.sections = [...this.$el.querySelectorAll('a[href^="#"]')]
				.map((link) => document.getElementById(link.hash.slice(1)))
				.filter(Boolean);
			this.update();
			let queued = false;
			this.onScroll = () => {
				if (!queued) {
					queued = true;
					requestAnimationFrame(() => {
						queued = false;
						this.update();
					});
				}
			};
			globalThis.addEventListener('scroll', this.onScroll, { passive: true });
		},

		destroy() {
			globalThis.removeEventListener('scroll', this.onScroll);
		},

		update() {
			const root = document.documentElement;
			if (this.sections.length > 0 && globalThis.scrollY > 0 && globalThis.scrollY + globalThis.innerHeight >= root.scrollHeight - 2) {
				this.current = this.sections.at(-1).id;
				return;
			}
			const passed = this.sections.filter((section) => section.getBoundingClientRect().top <= LINE);
			this.current = (passed.at(-1) ?? this.sections[0])?.id ?? '';
		},

		isCurrent(id) {
			return this.current === id;
		},
	};
}
