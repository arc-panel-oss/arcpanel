import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

// x-show applies a change on the next animation frame
const settle = async () => {
	await new Promise((resolve) => setTimeout(resolve, 50));
	await tick();
};

const markup = (options = '') => `
<form id="record" x-data="apDnsRecord(${options})">
	<select id="type" name="type" x-model="type">
		<option value="A">A</option>
		<option value="MX">MX</option>
		<option value="SRV">SRV</option>
		<option value="TXT">TXT</option>
	</select>
	<div id="priority-field" x-show="hasPriority">
		<input id="priority" name="priority" x-bind:disabled="! hasPriority">
	</div>
</form>`;

const choose = async (type) => {
	const select = document.getElementById('type');
	select.value = type;
	select.dispatchEvent(new Event('change'));
	await settle();
};

afterEach(() => unmount());

describe('apDnsRecord', () => {
	it('hides and disables the priority of an A record', async () => {
		mount(markup());
		await tick();
		expect(data(document.getElementById('record')).hasPriority).toBe(false);
		expect(document.getElementById('priority-field').style.display).toBe('none');
		expect(document.getElementById('priority').disabled).toBe(true);
	});

	it('shows the priority for MX and SRV records only', async () => {
		mount(markup());
		await choose('MX');
		expect(document.getElementById('priority-field').style.display).toBe('');
		expect(document.getElementById('priority').disabled).toBe(false);

		await choose('SRV');
		expect(document.getElementById('priority').disabled).toBe(false);

		await choose('TXT');
		expect(document.getElementById('priority').disabled).toBe(true);
	});

	it('starts from the type of the record being edited', async () => {
		mount(markup("{ type: 'MX' }"));
		await tick();
		expect(document.getElementById('type').value).toBe('MX');
		expect(document.getElementById('priority').disabled).toBe(false);
	});
});
