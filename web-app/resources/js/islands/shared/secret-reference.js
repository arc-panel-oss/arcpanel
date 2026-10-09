// References to the secret store of the account (E38): {{secret:NAME}} in
// an environment value, resolved by the server when the app starts.

/** The reference to the secret NAME */
export function secretReference(name) {
	return `{{secret:${name}}}`;
}

/** Whether a value references the secret store */
export function isSecretReference(value) {
	return /\{\{secret:[^}]+\}\}/.test(value);
}

/** Whether a value is exactly one reference, which may be shown where a password may not */
export function isOnlySecretReference(value) {
	return /^\{\{secret:[^}]+\}\}$/.test(value);
}
