import { afterEach, describe, expect, it } from 'vitest';
import { data, mount, tick, unmount } from './helpers.js';

// x-show applies a change on the next animation frame
const settle = async () => {
	await new Promise((resolve) => setTimeout(resolve, 50));
	await tick();
};

const markup = (options = '') => `
<fieldset id="ns" x-data="apNameServers(${options})">
	<template x-for="(server, index) in servers" :key="index">
		<div class="row">
			<input type="text" name="ns[]" x-model="servers[index]" :data-ns-index="index">
			<button type="button" class="remove" x-show="canRemove" x-on:click="remove(index)">x</button>
		</div>
	</template>
	<button type="button" id="add" x-show="canAdd" x-on:click="add()">Add Name Server</button>
</fieldset>`;

const inputs = () => [...document.querySelectorAll('input[name="ns[]"]')];
const values = () => inputs().map((input) => input.value);

afterEach(() => unmount());

describe('apNameServers', () => {
	it('shows two empty fields at least', async () => {
		mount(markup());
		await tick();
		expect(values()).toEqual(['', '']);
		expect(data(document.getElementById('ns')).canRemove).toBe(false);
	});

	it('prefills the user name servers and drops empty ones', async () => {
		mount(markup("{ values: ['ns1.example.net', '', 'ns2.example.net', 'ns3.example.net'] }"));
		await tick();
		expect(values()).toEqual(['ns1.example.net', 'ns2.example.net', 'ns3.example.net']);
	});

	it('adds fields up to eight and focuses the new one', async () => {
		mount(markup("{ values: ['a.example', 'b.example'] }"));
		const ns = data(document.getElementById('ns'));
		for (let i = 0; i < 10; i++) {
			document.getElementById('add').click();
		}
		await settle();
		expect(inputs()).toHaveLength(8);
		expect(ns.canAdd).toBe(false);
		expect(document.getElementById('add').style.display).toBe('none');
		expect(document.activeElement).toBe(inputs()[7]);
	});

	it('keeps eight of more prefilled values', async () => {
		const nine = Array.from({ length: 9 }, (_, i) => `'ns${i}.example'`).join(', ');
		mount(markup(`{ values: [${nine}] }`));
		await tick();
		expect(inputs()).toHaveLength(8);
	});

	it('removes a field but never below two', async () => {
		mount(markup("{ values: ['a.example', 'b.example', 'c.example'] }"));
		const ns = data(document.getElementById('ns'));
		await tick();

		ns.remove(0);
		await tick();
		expect(values()).toEqual(['b.example', 'c.example']);

		ns.remove(1);
		ns.remove(5);
		await settle();
		expect(values()).toEqual(['b.example', 'c.example']);
		expect([...document.querySelectorAll('.remove')].every((button) => button.style.display === 'none')).toBe(true);
	});

	it('posts what was typed', async () => {
		mount(markup());
		await tick();
		const [first] = inputs();
		first.value = 'ns9.example.net';
		first.dispatchEvent(new Event('input'));
		await tick();
		expect(data(document.getElementById('ns')).servers[0]).toBe('ns9.example.net');
	});
});
