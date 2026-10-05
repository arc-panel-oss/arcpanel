// Build JS and CSS using esbuild and Lightning CSS
import { createHash } from 'node:crypto';
import { watch as fsWatch, promises as fs } from 'node:fs';
import path from 'node:path';
import browserslist from 'browserslist';
import esbuild from 'esbuild';
import * as lightningcss from 'lightningcss';
import { vueDefines, vuePlugin } from './scripts/esbuild-vue.js';

// Packages to build but exclude from bundle
const externalPackages = [
	'chart.js/auto',
	'alpinejs/dist/cdn.min.js',
	'@alpinejs/collapse/dist/cdn.min.js',
	'@xterm/xterm',
	'@xterm/addon-webgl',
];

// Build main bundle
async function buildJS() {
	const inputPath = './web/js/src/index.js';
	try {
		await esbuild.build({
			entryPoints: [inputPath],
			outfile: './web/js/dist/main.min.js',
			bundle: true,
			minify: true,
			sourcemap: true,
			external: externalPackages,
		});
		console.log('✅ JavaScript build completed for', inputPath);
	} catch (error) {
		console.error('❌ Error building JavaScript:', error);
		process.exit(1);
	}
}

// Build external packages
async function buildExternalJS() {
	try {
		const buildPromises = externalPackages.map(async (pkg) => {
			const outputPath = getOutputPath(pkg);
			await esbuild.build({
				entryPoints: [pkg],
				outfile: outputPath,
				bundle: true,
				minify: true,
				format: 'esm',
				mainFields: ['main'],
			});
			console.log(`✅ Dependency build completed for ${pkg}`);
		});

		await Promise.all(buildPromises);
	} catch (error) {
		console.error('❌ Error building external packages:', error);
		process.exit(1);
	}
}

function getOutputPath(pkg) {
	let pkgName;

	if (pkg.startsWith('alpinejs')) {
		pkgName = 'alpinejs';
	} else if (pkg.startsWith('@alpinejs/collapse')) {
		pkgName = 'alpinejs-collapse';
	} else if (pkg === '@xterm/xterm') {
		pkgName = 'xterm';
	} else if (pkg.startsWith('@xterm/')) {
		pkgName = pkg.replace('@xterm/', 'xterm-');
	} else {
		pkgName = pkg.replace(/\//g, '-');
	}

	return `./web/js/dist/${pkgName}.min.js`;
}

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
		visitor: {
			Url: (node) => {
				// Fix relative paths for webfonts
				if (node.url.startsWith('../webfonts/')) {
					return {
						url: node.url.replace('../webfonts/', '/webfonts/'),
						loc: node.loc,
					};
				}
				return node;
			},
		},
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

// Process a CSS file
async function processCSS(inputFile, outputFile) {
	try {
		await ensureDir(path.dirname(outputFile));
		const bundle = await bundleCSS(inputFile);
		await fs.writeFile(outputFile, bundle.code);
		await fs.writeFile(`${outputFile}.map`, bundle.map);
		console.log(`✅ CSS build completed for ${inputFile}`);
	} catch (error) {
		console.error(`❌ Error processing CSS for ${inputFile}:`, error);
		process.exit(1);
	}
}

// Build CSS
async function buildCSS() {
	const themesSourcePath = './web/css/src/themes/';
	const cssEntries = await fs.readdir(themesSourcePath);

	const cssBuildPromises = cssEntries
		.filter((entry) => path.extname(entry) === '.css')
		.map(async (entry) => {
			const entryName = entry.replace('.css', '.min.css');
			const inputPath = path.join(themesSourcePath, entry);
			const outputPath = `./web/css/themes/${entryName}`;
			await processCSS(inputPath, outputPath);
		});

	await Promise.all(cssBuildPromises);
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

// Build all assets
async function build() {
	console.log('🚀 Building JS and CSS...');
	await buildJS();
	await buildExternalJS();
	await buildCSS();
	await buildPanelApp();
	await checkPanelApp();
	console.log('🎉 Build completed.');
}

// Execute build
await build();
if (process.argv.includes('--watch')) {
	watchPanelApp();
}
