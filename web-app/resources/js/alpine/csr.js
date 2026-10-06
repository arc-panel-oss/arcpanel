// apCsr: the CSR generator of the SSL forms (E7 batch 3 spec §12.2). POSTs
// the fields to url, fills the certificate and key fields of the target form
// with the self-signed certificate and its key, and shows the CSR to copy.
// Nothing of the answer is kept beyond the page.
import { request } from '../http.js';

export default function apCsr({ url = '', form = '' } = {}) {
	return {
		url,
		busy: false,
		error: '',
		errors: {},
		csr: '',
		copied: false,

		async generate() {
			const fields = Object.fromEntries(
				[...this.$refs.fields.querySelectorAll('input[name], select[name], textarea[name]')].map((field) => [
					field.name,
					field.value,
				]),
			);
			this.busy = true;
			this.error = '';
			this.errors = {};
			try {
				const data = await request(this.url, { method: 'POST', body: fields });
				this.fill('certificate', data.crt);
				this.fill('key', data.key);
				this.csr = data.csr;
			} catch (e) {
				this.error = e.message;
				this.errors = e.errors ?? {};
			} finally {
				this.busy = false;
			}
		},

		/** Put a value into a field of the SSL form and tell Alpine about it. */
		fill(name, value) {
			const field = document.getElementById(form)?.querySelector(`[name="${name}"]`);
			if (field) {
				field.value = value ?? '';
				field.dispatchEvent(new Event('input', { bubbles: true }));
			}
		},

		fieldError(name) {
			return this.errors[name]?.[0] ?? '';
		},

		async copy() {
			try {
				await globalThis.navigator?.clipboard?.writeText(this.csr);
			} catch {
				return;
			}
			this.copied = true;
			setTimeout(() => {
				this.copied = false;
			}, 1500);
		},
	};
}
