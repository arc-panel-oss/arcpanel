// apUserMenu: the account menu. Theme and density apply at once to <html>
// and are kept in the cookie arc_ui (D8 of the E1-S2 spec); the server
// renders them on the next page, so pages never flash.

export const PREFERENCES_COOKIE = 'arc_ui';
export const THEMES = ['nocturne', 'light', 'auto'];
export const DENSITIES = ['comfortable', 'compact'];

/** @param {{theme: string, density: string}} preferences */
export function writePreferences(preferences) {
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	// biome-ignore lint/suspicious/noDocumentCookie: the Cookie Store API is missing in Firefox and Safari
	document.cookie = `${PREFERENCES_COOKIE}=${encodeURIComponent(JSON.stringify(preferences))}; path=/; max-age=31536000; SameSite=Lax${secure}`;
}

export default function apUserMenu({ theme = 'nocturne', density = 'comfortable', canChangeTheme = true } = {}) {
	return {
		open: false,
		theme,
		density,
		canChangeTheme,

		toggle() {
			this.open = !this.open;
		},

		close(focusButton = false) {
			this.open = false;
			if (focusButton) {
				this.$refs.button?.focus();
			}
		},

		setTheme(value) {
			if (!this.canChangeTheme || !THEMES.includes(value)) {
				return;
			}
			this.theme = value;
			document.documentElement.dataset.theme = value;
			writePreferences({ theme: this.theme, density: this.density });
		},

		setDensity(value) {
			if (!DENSITIES.includes(value)) {
				return;
			}
			this.density = value;
			document.documentElement.dataset.density = value;
			writePreferences({ theme: this.theme, density: this.density });
		},
	};
}
