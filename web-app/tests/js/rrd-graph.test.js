import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chartData, labelFormat, loadAverage, memoryStack, PERIODS, trimEmpty } from '../../resources/js/alpine/rrd-graph.js';
import { data, jsonResponse, mount, tick, unmount } from './helpers.js';

const { Chart, charts } = vi.hoisted(() => {
	const charts = [];
	const Chart = vi.fn(
		class {
			constructor(canvas, config) {
				this.canvas = canvas;
				this.config = config;
				this.destroy = vi.fn();
				charts.push(this);
			}
		},
	);
	return { Chart, charts };
});

vi.mock('chart.js/auto', () => ({ Chart }));

const rrd = {
	unit: 'Points',
	meta: { start: 1700000000, step: 300, legend: ['1 min', '5 min'] },
	data: [
		[0.5, 0.4],
		[0.7, 0.6],
	],
};

const markup = `<div id="graph" x-data="apRrdGraph({ url: '/_rrd', service: 'la' })"><canvas x-ref="canvas"></canvas></div>`;
let fetchMock;

beforeEach(() => {
	charts.length = 0;
	Chart.mockClear();
	fetchMock = vi.fn(async () => jsonResponse(rrd));
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	unmount();
	vi.unstubAllGlobals();
});

describe('chartData', () => {
	it('turns the RRD export into labels and one dataset per legend', () => {
		const result = chartData(rrd, ['red', 'blue']);
		expect(result.labels).toEqual([new Date(1700000000 * 1000), new Date(1700000300 * 1000)]);
		expect(result.datasets.map((d) => [d.label, d.data, d.borderColor])).toEqual([
			['1 min', [0.5, 0.7], 'red'],
			['5 min', [0.4, 0.6], 'blue'],
		]);
	});

	it('cycles colours and copes with missing data', () => {
		const three = { meta: { start: 0, step: 1, legend: ['a', 'b', 'c'] }, data: [[1, 2, 3]] };
		expect(chartData(three, ['x', 'y']).datasets[2].borderColor).toBe('x');
		expect(chartData(null, ['x'])).toEqual({ labels: [], datasets: [] });
	});
});

describe('loadAverage', () => {
	it('draws only the load, scaled back from hundredths, and tells the latest process count', () => {
		const la = { meta: { start: 0, step: 1, legend: ['Load', 'Processes'] }, data: [[24, 80], [null, 81], [135, '82'], [140, null]] };
		const result = loadAverage(chartData(la, ['x', 'y']));
		expect(result.data.datasets.map((d) => [d.label, d.data])).toEqual([['Load', [0.24, null, 1.35, 1.4]]]);
		expect(result.note).toBe('Processes: 82');
		expect(loadAverage({ labels: [], datasets: [] })).toEqual({ data: { labels: [], datasets: [] }, note: '' });
	});
});

describe('trimEmpty', () => {
	it('drops the rows before the first value and after the last one', () => {
		const fresh = { meta: { start: 0, step: 1, legend: ['a', 'b'] }, data: [[null, null], [null, null], [1, null], [null, 2], [null, null]] };
		const result = trimEmpty(chartData(fresh, ['x']));
		expect(result.labels).toEqual([new Date(2000), new Date(3000)]);
		expect(result.datasets.map((d) => d.data)).toEqual([
			[1, null],
			[null, 2],
		]);
	});

	it('keeps a graph without any value as it is', () => {
		const empty = chartData({ meta: { start: 0, step: 1, legend: ['a'] }, data: [[null], [null]] }, ['x']);
		expect(trimEmpty(empty)).toBe(empty);
		expect(trimEmpty({ labels: [], datasets: [] })).toEqual({ labels: [], datasets: [] });
	});
});

describe('memoryStack', () => {
	it('stacks used and free RAM as areas and keeps swap apart', () => {
		const mem = { meta: { start: 0, step: 1, legend: ['Used', 'Swap', 'Free'] }, data: [[650, 0, 5250]] };
		const result = memoryStack(chartData(mem, ['#2f55d4', '#1a7a44', 'oklch(0.7 0.1 200)']));
		expect(result.stacked).toBe(true);
		expect(result.data.datasets.map((d) => [d.label, d.stack, d.fill, d.backgroundColor])).toEqual([
			['Used', 'ram', 'origin', '#2f55d433'],
			['Free', 'ram', '-1', 'oklch(0.7 0.1 200)'],
			['Swap', 'swap', false, undefined],
		]);
		const other = chartData({ meta: { start: 0, step: 1, legend: ['a'] }, data: [[1]] }, ['x']);
		expect(memoryStack(other)).toEqual({ data: other, stacked: false });
	});
});

describe('apRrdGraph', () => {
	it('loads the daily graph and draws it on the canvas', async () => {
		mount(markup);
		await tick();
		expect(fetchMock.mock.calls[0][0]).toBe('/_rrd?service=la&period=daily');
		expect(Chart).toHaveBeenCalledTimes(1);
		expect(charts[0].canvas).toBe(document.querySelector('canvas'));
		expect(charts[0].config.type).toBe('line');
		// The load graph: one series on one axis named "Load Average" (not the RRD unit "Points"), no legend box
		expect(charts[0].config.data.datasets).toHaveLength(1);
		expect(charts[0].config.options.scales.y.title.text).toBe('Load Average');
		expect(charts[0].config.options.scales.y1).toBeUndefined();
		expect(charts[0].config.options.plugins.legend.display).toBe(false);
		expect(charts[0].config.data.datasets[0].data.map((v) => Number(v.toFixed(4)))).toEqual([0.005, 0.007]);
		expect(data(document.getElementById('graph')).note).toBe('5 min: 1');
		expect(data(document.getElementById('graph')).loading).toBe(false);
	});

	it('reloads for another period and replaces the chart', async () => {
		mount(markup);
		await tick();
		const graph = data(document.getElementById('graph'));

		await graph.setPeriod('weekly');
		expect(fetchMock.mock.calls[1][0]).toBe('/_rrd?service=la&period=weekly');
		expect(charts[0].destroy).toHaveBeenCalled();
		expect(charts).toHaveLength(2);

		await graph.setPeriod('weekly');
		await graph.setPeriod('hourly');
		expect(fetchMock).toHaveBeenCalledTimes(2);
		expect(PERIODS).toContain('yearly');
	});

	it('knows the two- and three-year periods of the Task Monitor page', () => {
		expect(PERIODS).toEqual(['daily', 'weekly', 'monthly', 'yearly', 'biennially', 'triennially']);
		expect(labelFormat('daily')).toEqual({ hour: '2-digit', minute: '2-digit' });
		expect(labelFormat('weekly')).toEqual({ weekday: 'short', day: 'numeric' });
		expect(labelFormat('monthly')).toEqual({ month: 'short', day: 'numeric' });
		expect(labelFormat('yearly')).toEqual({ month: 'long' });
		expect(labelFormat('triennially')).toEqual({ month: 'short', year: 'numeric' });
	});

	it('shows the error when the data cannot be loaded', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ error: { message: 'RRD missing' } }, 500));
		mount(markup);
		await tick();
		const graph = data(document.getElementById('graph'));
		expect(graph.error).toBe('RRD missing');
		expect(Chart).not.toHaveBeenCalled();
		expect(graph.loading).toBe(false);
	});

	it('destroys the chart with the component', async () => {
		mount(markup);
		await tick();
		unmount();
		expect(charts[0].destroy).toHaveBeenCalled();
	});
});
