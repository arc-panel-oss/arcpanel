// apMailAccount: the mail account forms (web/add/mail?domain=, web/edit/mail?account=).
// The client configuration panel mirrors the account name and the password
// as they are typed (email-settings-panel.php); "Discard all mail" disables
// the forward list and hides "Do not store forwarded mail"; copy buttons put
// a value on the clipboard.

export default function apMailAccount({ account = '', domain = '', blackhole = false, fwdOnly = false } = {}) {
	return {
		account,
		domain,
		blackhole,
		fwdOnly,
		password: '',
		copied: '',

		get username() {
			return `${this.account}@${this.domain}`;
		},

		/** Input events of the wrapped forms: the account name and the password. */
		sync(event) {
			const target = event?.target;
			if (!target || typeof target.value !== 'string') {
				return;
			}
			if (target.name === 'password') {
				this.password = target.value;
			} else if (target.name === 'account') {
				this.account = target.value.trim().toLowerCase();
			}
		},

		/** Copy a value; `copied` names the field for a moment. */
		async copy(key, value) {
			try {
				await globalThis.navigator?.clipboard?.writeText(value);
			} catch {
				return;
			}
			this.copied = key;
			setTimeout(() => {
				if (this.copied === key) {
					this.copied = '';
				}
			}, 1500);
		},
	};
}
