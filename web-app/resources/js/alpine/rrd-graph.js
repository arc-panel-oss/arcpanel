// apRrdGraph: server graphs from `v-export-rrd` (port of rrdCharts.js).
// chart.js is loaded on demand as a separate chunk.
import { request } from '../http.js';
import { __ } from '../i18n.js';

export const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'biennially', 'triennially'];

/** toLocaleString() options of the x axis labels per period, as rrdCharts.js shows them. */
export function labelFormat(period) {
	switch (period) {
		case 'daily':
			return { hour: '2-digit', minute: '2-digit' };
		case 'weekly':
			return { weekday: 'short', day: 'numeric' };
		case 'monthly':
			return { month: 'short', day: 'numeric' };
		case 'yearly':
			return { month: 'long' };
		default:
			return { month: 'short', year: 'numeric' };
	}
}

/** Chart.js data from the JSON of GET url?service=&period=. */
export function chartData(rrd, colours) {
	const meta = rrd?.meta ?? { start: 0, step: 0, legend: [] };
	const rows = Array.isArray(rrd?.data) ? rrd.data : [];
	return {
		labels: rows.map((_, index) => new Date((meta.start + index * meta.step) * 1000)),
		datasets: (meta.legend ?? []).map((legend, legendIndex) => ({
			label: legend,
			data: rows.map((row) => row[legendIndex]),
			tension: 0.3,
			pointStyle: false,
			borderWidth: 2,
			borderColor: colours[legendIndex % colours.length],
		})),
	};
}

/**
 * The load graph: v-update-sys-rrd-la stores the load average times 100 next
 * to the process count. One axis only: the load is scaled back and drawn
 * alone, the process count is told as its latest value ("Processes: 82").
 *
 * @returns {{ data: object, note: string }}
 */
export function loadAverage(data) {
	const [load, processes] = data.datasets;
	if (!load) {
		return { data, note: '' };
	}
	const latest = processes ? [...processes.data].reverse().find((value) => !missing(value)) : undefined;
	return {
		data: { ...data, datasets: [{ ...load, data: load.data.map((value) => (missing(value) ? null : Number(value) / 100)) }] },
		note: latest === undefined ? '' : `${processes.label}: ${Math.round(Number(latest))}`,
	};
}

function missing(value) {
	return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
}

/**
 * Drop the rows before the first value and after the last one: a fresh
 * server has data for the last hours of a period only, which would otherwise
 * be squeezed against the right edge.
 */
export function trimEmpty(data) {
	const filled = (index) => data.datasets.some((dataset) => !missing(dataset.data[index]));
	let first = 0;
	let last = data.labels.length - 1;
	while (first <= last && !filled(first)) {
		first++;
	}
	while (last >= first && !filled(last)) {
		last--;
	}
	if (first > last) {
		return data;
	}
	return {
		...data,
		labels: data.labels.slice(first, last + 1),
		datasets: data.datasets.map((dataset) => ({ ...dataset, data: dataset.data.slice(first, last + 1) })),
	};
}

/** A #rrggbb colour at 20 % opacity for area fills; other colours unchanged. */
function translucent(colour) {
	return /^#[0-9a-f]{6}$/i.test(colour) ? `${colour}33` : colour;
}

/**
 * The memory graph (v-update-sys-rrd-mem: used, swap, free in MB): used and
 * free stacked as areas, so the top edge is the total RAM and the used part
 * stays visible next to a large free part; swap is a line of its own.
 *
 * @returns {{ data: object, stacked: boolean }}
 */
export function memoryStack(data) {
	const [used, swap, free] = data.datasets;
	if (!used || !swap || !free) {
		return { data, stacked: false };
	}
	return {
		data: {
			...data,
			datasets: [
				{ ...used, stack: 'ram', fill: 'origin', backgroundColor: translucent(used.borderColor) },
				{ ...free, stack: 'ram', fill: '-1', backgroundColor: translucent(free.borderColor) },
				{ ...swap, stack: 'swap', fill: false },
			],
		},
		stacked: true,
	};
}

function cssVar(name) {
	return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

export default function apRrdGraph({ url = '', service = 'la', period = 'daily' } = {}) {
	return {
		url,
		service,
		period,
		loading: false,
		error: '',
		note: '',
		chart: null,

		async init() {
			await this.load();
		},

		destroy() {
			this.chart?.destroy();
			this.chart = null;
		},

		async setPeriod(value) {
			if (!PERIODS.includes(value) || value === this.period) {
				return;
			}
			this.period = value;
			await this.load();
		},

		async load() {
			this.loading = true;
			this.error = '';
			try {
				const rrd = await request(
					`${this.url}?service=${encodeURIComponent(this.service)}&period=${encodeURIComponent(this.period)}`,
				);
				const { Chart } = await import('chart.js/auto');
				const series = trimEmpty(
					chartData(rrd, [cssVar('--ap-accent'), cssVar('--ap-ok'), cssVar('--ap-warn'), cssVar('--ap-info')]),
				);
				const isLoad = this.service === 'la' && series.datasets.length > 1;
				const load = isLoad ? loadAverage(series) : null;
				const memory = this.service === 'mem' ? memoryStack(series) : { data: series, stacked: false };
				const data = load ? load.data : memory.data;
				this.note = load ? load.note : '';
				const text = cssVar('--ap-fg-muted');
				const grid = cssVar('--ap-border');
				const labels = labelFormat(this.period);
				this.chart?.destroy();
				this.chart = new Chart(this.$refs.canvas, {
					type: 'line',
					data: { ...data, labels: data.labels.map((date) => date.toLocaleString([], labels)) },
					options: {
						animation: false,
						maintainAspectRatio: false,
						// One series is named by the card title: no legend box
						plugins: { legend: { display: data.datasets.length > 1, position: 'bottom', labels: { color: text } } },
						scales: {
							x: { ticks: { color: text, maxTicksLimit: 8 }, grid: { color: grid } },
							y: {
								title: isLoad
									? { display: true, text: __('Load Average'), color: text }
									: { display: Boolean(rrd?.unit), text: rrd?.unit ?? '', color: text },
								ticks: { color: text },
								grid: { color: grid },
								stacked: memory.stacked,
								beginAtZero: memory.stacked,
							},
						},
					},
				});
			} catch (error) {
				this.error = error.message;
			} finally {
				this.loading = false;
			}
		},
	};
}
