// esbuild plugin for the Vue islands of the panel app (ADR-0001, E13 spec
// §7.1): compiles single-file components with <script setup> and their
// template ahead of time, so the page runs Vue's runtime-only build and no
// template is compiled in the browser (Content Security Policy). Islands use
// the panel stylesheet; <style> blocks are refused.
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { compileScript, parse } from 'vue/compiler-sfc';

/** @returns {import('esbuild').Plugin} */
export function vuePlugin() {
	return {
		name: 'vue-sfc',
		setup(build) {
			build.onLoad({ filter: /\.vue$/ }, async (args) => {
				const source = await readFile(args.path, 'utf8');
				const { descriptor, errors } = parse(source, { filename: args.path });
				if (errors.length > 0) {
					return { errors: errors.map((error) => ({ text: String(error.message ?? error) })) };
				}
				if (descriptor.styles.length > 0) {
					return {
						errors: [
							{ text: 'Islands use the panel stylesheet: <style> blocks are not supported' },
						],
					};
				}
				if (!descriptor.scriptSetup) {
					return { errors: [{ text: 'Islands are written with <script setup>' }] };
				}
				const id = createHash('sha256').update(args.path).digest('hex').slice(0, 8);
				const script = compileScript(descriptor, { id, inlineTemplate: true, isProd: true });
				return { contents: script.content, loader: 'js', resolveDir: path.dirname(args.path) };
			});
		},
	};
}

// Compile-time flags of Vue's esm-bundler build
export const vueDefines = {
	__VUE_OPTIONS_API__: 'false',
	__VUE_PROD_DEVTOOLS__: 'false',
	__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
	'process.env.NODE_ENV': '"production"',
};
