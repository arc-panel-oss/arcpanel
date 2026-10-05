import { afterEach, describe, expect, it } from 'vitest';
import { CRON_FIELDS, CRON_GENERATOR, CRON_PRESETS } from '../../resources/js/alpine/cron-builder.js';
import { data, mount, tick, unmount } from './helpers.js';

const markup = `
<form>
	${CRON_FIELDS.map((field) => `<input name="v_${field}" value="5">`).join('')}
	<div id="builder" x-data="apCronBuilder({ prefix: 'v_' })">
		<button id="daily" type="button" x-on:click="preset('daily')">Daily</button>
		<button id="apply" type="button" x-on:click="apply()">Apply</button>
		<button id="tab-monthly" type="button" x-on:click="tab = 'monthly'">Monthly</button>
		<select id="month" x-model="gen.monthly.month"><option value="*">Every month</option><option value="*/3">Every 3 months</option></select>
		<button id="generate" type="button" x-on:click="generate()">Generate</button>
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

	it('has the legacy defaults per generator tab', () => {
		for (const choice of Object.values(CRON_GENERATOR)) {
			expect(Object.keys(choice)).toEqual(CRON_FIELDS);
		}
		expect(CRON_GENERATOR.monthly).toEqual({ min: '0', hour: '12', day: '1', month: '*', wday: '*' });
	});
});

describe('apCronBuilder', () => {
	it('starts from the form fields and builds the expression from a preset', () => {
		mount(markup);
		const builder = data(document.getElementById('builder'));
		expect(builder.expression).toBe('5 5 5 5 5');
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

	it('follows fields typed by hand, and marks empty ones', () => {
		mount(markup);
		const builder = data(document.getElementById('builder'));
		const min = document.querySelector('[name="v_min"]');
		min.value = '';
		min.dispatchEvent(new Event('input', { bubbles: true }));
		expect(builder.expression).toBe('? 5 5 5 5');
	});

	it('generates the fields from the choices of the open tab', async () => {
		mount(markup);
		document.getElementById('tab-monthly').click();
		const month = document.getElementById('month');
		month.value = '*/3';
		month.dispatchEvent(new Event('change'));
		await tick();
		document.getElementById('generate').click();
		expect(values()).toEqual(['0', '12', '1', '*/3', '*']);
	});

	it('keeps generator choices per instance', () => {
		mount(markup);
		data(document.getElementById('builder')).gen.daily.hour = '3';
		expect(CRON_GENERATOR.daily.hour).toBe('12');
	});
});
