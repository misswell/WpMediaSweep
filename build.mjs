/**
 * Build the MediaSweep admin SPA.
 *
 * JSX is transformed with the classic factory (wp.element.createElement), so no
 * React runtime is bundled; @wordpress/* packages resolve to the global wp.*
 * objects provided by wp-admin.
 */
import * as esbuild from 'esbuild';
import { mkdirSync, writeFileSync, statSync } from 'node:fs';
import { createHash } from 'node:crypto';

const watch = process.argv.includes('--watch');

const outfile = 'wp-mediasweep/admin/assets/build/app.js';

const wpDependencies = [
	'wp-element',
	'wp-components',
	'wp-i18n',
	'wp-api-fetch',
	'wp-compose',
];

/** @type {import('esbuild').BuildOptions} */
const options = {
	entryPoints: ['frontend-src/index.jsx'],
	bundle: true,
	outfile,
	format: 'iife',
	target: ['es2019'],
	jsx: 'transform',
	jsxFactory: 'wp.element.createElement',
	jsxFragment: 'wp.element.Fragment',
	minify: true,
	sourcemap: false,
	external: ['@wordpress/*'],
	define: { 'process.env.NODE_ENV': '"production"' },
	loader: { '.js': 'jsx' },
};

if (watch) {
	const ctx = await esbuild.context(options);
	await ctx.watch();
	console.log('Watching for changes…');
} else {
	const result = await esbuild.build({ ...options, write: false });

	// Generate app.asset.php (WordPress script dependency manifest) and write the bundle.
	const js = result.outputFiles ? result.outputFiles[0] : null;
	const version = createHash('md5').update(js ? js.contents : String(Date.now())).digest('hex').slice(0, 12);

	mkdirSync('wp-mediasweep/admin/assets/build', { recursive: true });
	writeFileSync(outfile, js ? js.contents : '');
	writeFileSync(
		'wp-mediasweep/admin/assets/build/app.asset.php',
		`<?php return array(\n    'dependencies' => ${JSON.stringify(wpDependencies)},\n    'version' => '${version}',\n);`
	);
	console.log(`Built ${outfile} (${js ? js.contents.length : '?'} bytes, v${version})`);
}
