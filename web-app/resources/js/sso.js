// Single sign-on pages of phpMyAdmin and webmail (db/sso, mail/webmail-sso):
// post the form at once, so the token never goes into a URL. A module of its
// own, because the Content Security Policy runs no inline script (E39 W5
// spec §9.2).

/** @param {Document} doc */
export function submitSso(doc) {
	const form = doc.querySelector('form[data-ap-sso]');
	if (form instanceof HTMLFormElement) {
		form.submit();
	}
}

submitSso(document);
