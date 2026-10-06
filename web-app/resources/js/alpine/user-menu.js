// apUserMenu: the account menu. Theme and density apply at once to <html>
// and are kept in the cookie arc_ui (D8 of the E1-S2 spec), next to the
// sidebar layout of apSidebar; the server renders them on the next page, so
// pages never flash.

export const PREFERENCES_COOKIE = 'arc_ui';
export const THEMES = ['nocturne', 'light', 'auto'];
export const DENSITIES = ['comfortable', 'compact'];

/** The preferences in arc_ui, {} when it is missing or unreadable. */
export function readPreferences() {
	const entry = document.cookie.split('; ').find((part) => part.startsWith(`${PREFERENCES_COOKIE}=`));
	if (!entry) {
		return {};
	}
	try {
		const value = JSON.parse(decodeURIComponent(entry.slice(PREFERENCES_COOKIE.length + 1)));
		return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {};
	} catch {
		return {};
	}
}

/**
 * Stores preferences in arc_ui; keys that are not given keep their value.
 * @param {{theme?: string, density?: string, sidebar?: string}} preferences
 */
export function writePreferences(preferences) {
	const merged = { ...readPreferences(), ...preferences };
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	// biome-ignore lint/suspicious/noDocumentCookie: the Cookie Store API is missing in Firefox and Safari
	document.cookie = `${PREFERENCES_COOKIE}=${encodeURIComponent(JSON.stringify(merged))}; path=/; max-age=31536000; SameSite=Lax${secure}`;
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
