// Crop generated SVGs, render PNG exports and favicon.ico for the ArcPanel brand kit.
// Called by gen.py with a job file. Needs @resvg/resvg-js reachable via NODE_PATH.
const fs = require('node:fs');
const path = require('node:path');
const { Resvg } = require('@resvg/resvg-js');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const r2 = (v) => Math.round(v * 100) / 100;

// 1. Tighten each viewBox to the ink bounds (stroke included), snapped to 0.5 units.
for (const item of job.crop) {
	let svg = fs.readFileSync(item.file, 'utf8');
	const bb = new Resvg(svg).getBBox();
	const x = Math.floor(bb.x * 2 + 1e-6) / 2;
	let y = Math.floor(bb.y * 2 + 1e-6) / 2;
	let w = Math.ceil((bb.x + bb.width) * 2 - 1e-6) / 2 - x;
	let h = Math.ceil((bb.y + bb.height) * 2 - 1e-6) / 2 - y;
	if (item.aspect) {
		// Fixed aspect: keep the ink left-aligned and vertically centred.
		if (w / h < item.aspect) {
			w = h * item.aspect;
		} else {
			const nh = w / item.aspect;
			y -= (nh - h) / 2;
			h = nh;
		}
	}
	svg = svg.replace(/viewBox="[^"]*"/, `viewBox="${r2(x)} ${r2(y)} ${r2(w)} ${r2(h)}"`);
	fs.writeFileSync(item.file, svg);
}

// 2. PNG exports.
const brand = job.brand;
const render = (svg, width) =>
	new Resvg(svg, { fitTo: { mode: 'width', value: width }, background: 'rgba(0,0,0,0)' })
		.render()
		.asPng();
const iconSvg = fs.readFileSync(path.join(brand, 'icon.svg'), 'utf8');
// Apple touch icon: full-bleed square, the OS applies its own mask.
const iconFullBleed = iconSvg.replace(/ rx="[^"]*"/, '');
const pngs = {};
for (const size of [16, 32, 48, 128, 192, 512]) pngs[size] = render(iconSvg, size);
pngs[180] = render(iconFullBleed, 180);
for (const size of [16, 32, 180, 192, 512]) {
	fs.writeFileSync(path.join(brand, `icon-${size}.png`), pngs[size]);
}
fs.writeFileSync(
	path.join(brand, 'logo.png'),
	render(fs.readFileSync(path.join(brand, 'logo.svg'), 'utf8'), 1200),
);

// 3. favicon.ico with PNG-compressed 16, 32 and 48 px images.
function ico(images) {
	const header = Buffer.alloc(6 + 16 * images.length);
	header.writeUInt16LE(0, 0);
	header.writeUInt16LE(1, 2);
	header.writeUInt16LE(images.length, 4);
	let offset = header.length;
	images.forEach(([size, data], i) => {
		const e = 6 + i * 16;
		header.writeUInt8(size >= 256 ? 0 : size, e);
		header.writeUInt8(size >= 256 ? 0 : size, e + 1);
		header.writeUInt8(0, e + 2);
		header.writeUInt8(0, e + 3);
		header.writeUInt16LE(1, e + 4);
		header.writeUInt16LE(32, e + 6);
		header.writeUInt32LE(data.length, e + 8);
		header.writeUInt32LE(offset, e + 12);
		offset += data.length;
	});
	return Buffer.concat([header, ...images.map(([, data]) => data)]);
}
const favicon = ico([16, 32, 48].map((s) => [s, pngs[s]]));
fs.writeFileSync(path.join(brand, 'favicon.ico'), favicon);

// 4. Optional: copy the panel assets into web/.
if (job.applyWeb) {
	const web = path.join(job.root, 'web');
	fs.copyFileSync(path.join(brand, 'icon.svg'), path.join(web, 'images', 'logo.svg'));
	fs.copyFileSync(path.join(brand, 'logo-header.svg'), path.join(web, 'images', 'logo-header.svg'));
	fs.writeFileSync(path.join(web, 'images', 'logo.png'), pngs[128]);
	fs.writeFileSync(path.join(web, 'images', 'favicon.png'), pngs[32]);
	fs.writeFileSync(path.join(web, 'favicon.ico'), favicon);
}
console.log('Brand kit exported to', brand, job.applyWeb ? '(web assets updated)' : '');
