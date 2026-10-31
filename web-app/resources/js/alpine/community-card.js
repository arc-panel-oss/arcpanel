// apCommunityCard: the Community card of the admin dashboard. Hide keeps
// community: hidden in the cookie arc_ui, which the server reads to leave the
// card out (docs/features/specs/2026-11-01-001-community-touchpoints-design.md §4).
import { writePreferences } from './user-menu.js';

export default function apCommunityCard() {
	return {
		hide() {
			writePreferences({ community: 'hidden' });
			this.$root.remove();
		},
	};
}
