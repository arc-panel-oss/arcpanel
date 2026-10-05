import { afterEach, describe, expect, it } from 'vitest';
import { CRON_FIELDS, CRON_PRESETS } from '../../resources/js/alpine/cron-builder.js';
import { data, mount, unmount } from './helpers.js';

const markup = `
<form>
	${CRON_FIELDS.map((field) => `<input name="v_${field}" value="5">`).join('')}
	<div id="builder" x-data="apCronBuilder({ prefix: 'v_' })">
		<button id="daily" type="button" x-on:click="preset('daily')">Daily</button>
		<button id="apply" type="button" x-on:click="apply()">Apply</button>
		<code x-text="expression"></code>
	</div>
</form>`;

const values = () => CRON_FIELDS.map((field) => document.querySelector(`[name="v_${field}"]`).value);

afterEach(() => unmount());

describe('CRON_PRESETS', () => {
	it('has five fields per preset', () => {
		for (const preset of Object.values(CRON_PRESETS)) {
			expect(preset).toHaveLength(5);
		}
		expect(CRON_PRESETS.weekly).toEqual(['0', '0', '*', '*', '0']);
	});
});

describe('apCronBuilder', () => {
	it('builds the expression from a preset', () => {
		mount(markup);
		const builder = data(document.getElementById('builder'));
		expect(builder.expression).toBe('* * * * *');
		document.getElementById('daily').click();
		expect(builder.expression).toBe('0 0 * * *');
		builder.preset('monthly');
		expect(builder.expression).toBe('0 0 1 * *');
		builder.preset('nope');
		expect(builder.expression).toBe('0 0 1 * *');
	});

	it('copies the values into the form fields', () => {
		mount(markup);
		const inputs = [];
		document.querySelector('form').addEventListener('input', (event) => inputs.push(event.target.name));
		document.getElementById('daily').click();
		document.getElementById('apply').click();
		expect(values()).toEqual(['0', '0', '*', '*', '*']);
		expect(inputs).toEqual(CRON_FIELDS.map((field) => `v_${field}`));
	});
});
