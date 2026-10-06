// Build the panel app (web-app/) with esbuild and Lightning CSS
import { createHash } from 'node:crypto';
import { watch as fsWatch, promises as fs } from 'node:fs';
import path from 'node:path';
import browserslist from 'browserslist';
import esbuild from 'esbuild';
import * as lightningcss from 'lightningcss';
import { vueDefines, vuePlugin } from './scripts/esbuild-vue.js';

// Bundle a CSS file and its imports
async function bundleCSS(inputFile) {
	const css = await fs.readFile(inputFile);
	return lightningcss.bundleAsync({
		filename: inputFile,
		sourceMap: true,
		code: Buffer.from(css),
		minify: true,
		targets: lightningcss.browserslistToTargets(browserslist()),
		drafts: { customMedia: true, nesting: true },
		resolver: {
			resolve(specifier, from) {
				if (!specifier.endsWith('.css')) {
					specifier += '.css';
				}
				if (specifier.startsWith('node:')) {
					return `node_modules/${specifier.replace('node:', '')}`;
				}
				return `${path.dirname(from)}/${specifier}`;
			},
		},
	});
}

// Panel app (web-app/, docs/design/05 §7): hashed CSS and JS, bundled fonts
// and public/build/manifest.json, read by the @apAsset Blade directive.
const panelSource = './web-app/resources';
const panelOutput = './web-app/public/build';
const panelFonts = {
	inter: [400, 500, 600, 700],
	'jetbrains-mono': [400, 500, 600],
};
const panelFontSubsets = ['latin', 'latin-ext', 'vietnamese'];

function contentHash(content) {
	return createHash('sha256').update(content).digest('hex').slice(0, 10);
}

async function buildPanelApp() {
	try {
		await fs.rm(panelOutput, { recursive: true, force: true });
		await ensureDir(panelOutput);
		const manifest = {};

		const css = await bundleCSS(`${panelSource}/css/app.css`);
		const cssName = `app.${contentHash(css.code)}.css`;
		await fs.writeFile(`${panelOutput}/${cssName}`, css.code);
		await fs.writeFile(`${panelOutput}/${cssName}.map`, css.map);
		manifest['app.css'] = cssName;

		const js = await esbuild.build({
			entryPoints: { app: `${panelSource}/js/app.js` },
			outdir: panelOutput,
			bundle: true,
			minify: true,
			sourcemap: true,
			format: 'esm',
			splitting: true,
			target: 'es2020',
			entryNames: '[name].[hash]',
			chunkNames: 'chunks/[name].[hash]',
			metafile: true,
			// Vue islands (ADR-0001): precompiled single-file components
			plugins: [vuePlugin()],
			define: vueDefines,
			// Stylesheets imported by scripts (xterm.js of the web terminal) arrive
			// as text that the module adds to the page itself.
			loader: { '.css': 'text' },
		});
		for (const [file, output] of Object.entries(js.metafile.outputs)) {
			// Dynamic imports (chart.js) are entry points too; only list the app.
			if (output.entryPoint?.endsWith('resources/js/app.js') && file.endsWith('.js')) {
				manifest[`${path.basename(output.entryPoint)}`] = path
					.relative(panelOutput, file)
					.split(path.sep)
					.join('/');
			}
		}

		await ensureDir(`${panelOutput}/fonts`);
		for (const [family, weights] of Object.entries(panelFonts)) {
			for (const weight of weights) {
				for (const subset of panelFontSubsets) {
					const file = `${family}-${subset}-${weight}-normal.woff2`;
					await fs.copyFile(
						`./node_modules/@fontsource/${family}/files/${file}`,
						`${panelOutput}/fonts/${file}`,
					);
				}
			}
			// SIL Open Font License 1.1: the licence travels with the fonts.
			await fs.copyFile(
				`./node_modules/@fontsource/${family}/LICENSE`,
				`${panelOutput}/fonts/${family}-LICENSE.txt`,
			);
		}

		// Quick Install (E7 batch 2 spec §10.3): the logo of each installer
		const installers = './lib/quickinstall/app/WebApp/Installers';
		for (const app of await fs.readdir(installers)) {
			for (const file of await fs.readdir(`${installers}/${app}`)) {
				if (/\.(svg|png|jpe?g|webp)$/i.test(file)) {
					await ensureDir(`${panelOutput}/quick-install/${app}`);
					await fs.copyFile(
						`${installers}/${app}/${file}`,
						`${panelOutput}/quick-install/${app}/${file}`,
					);
				}
			}
		}

		await fs.writeFile(`${panelOutput}/manifest.json`, `${JSON.stringify(manifest, null, '\t')}\n`);
		console.log('✅ Panel app build completed');
	} catch (error) {
		console.error('❌ Error building the panel app:', error);
		process.exit(1);
	}
}

// Token and contrast checks of the panel app stylesheet (docs/design/05 §6)
async function checkPanelApp() {
	const { checkTokens } = await import('./scripts/check-tokens.js');
	const { checkContrast } = await import('./scripts/check-contrast.js');
	const problems = [...(await checkTokens()), ...(await checkContrast())];
	if (problems.length > 0) {
		for (const problem of problems) {
			console.error(`❌ ${problem}`);
		}
		process.exit(1);
	}
	console.log('✅ Panel app token and contrast checks passed');
}

// npm run dev: rebuild the panel app when its sources change
function watchPanelApp() {
	let timer = null;
	fsWatch(panelSource, { recursive: true }, (_event, file) => {
		if (!file || !/\.(css|js|vue)$/.test(file)) {
			return;
		}
		clearTimeout(timer);
		timer = setTimeout(async () => {
			await buildPanelApp();
			await checkPanelApp();
		}, 150);
	});
	console.log(`👀 Watching ${panelSource} for changes`);
}

// Ensure a directory exists
async function ensureDir(dir) {
	try {
		await fs.mkdir(dir, { recursive: true });
	} catch (error) {
		if (error.code !== 'EEXIST') {
			throw error;
		}
	}
}

// Build all assets (the legacy bundle of web/ is gone since 2.0, E8)
async function build() {
	console.log('🚀 Building the panel app...');
	await buildPanelApp();
	await checkPanelApp();
	console.log('🎉 Build completed.');
}

// Execute build
await build();
if (process.argv.includes('--watch')) {
	watchPanelApp();
}
