import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { transformSync } from 'esbuild';

function component( file, { states = [], modules = {}, hash = '' } = {} ) {
	const effects = [], timers = new Map();
	let nextTimer = 0, stateIndex = 0;
	const wp = {
		element: {
			useState: ( initial ) => [ states[ stateIndex++ ] ?? ( typeof initial === 'function' ? initial() : initial ), () => {} ],
			useEffect: ( fn ) => effects.push( fn ),
			useRef: ( initial ) => ( { current: initial } ),
			createElement: ( type, props, ...children ) => ( { type, props, children } ),
		},
		components: {}, i18n: { __: ( value ) => value },
	};
	const source = readFileSync( new URL( '../' + file, import.meta.url ), 'utf8' );
	const code = transformSync( source, { loader: 'jsx', format: 'cjs', jsxFactory: 'wp.element.createElement' } ).code;
	const module = { exports: {} };
	new Function( 'module', 'exports', 'require', 'wp', 'window', 'setInterval', 'clearInterval', code )(
		module, module.exports, ( path ) => modules[ path ] || {}, wp, { location: { hash } },
		( fn ) => { timers.set( ++nextTimer, fn ); return nextTimer; }, ( id ) => timers.delete( id )
	);
	return { render: module.exports.default, effects, timers };
}

let tickCount = 0, finish;
const taskPage = component( 'frontend-src/pages/Tasks.jsx', {
	states: [ [ { id: 1, type: 'compress', status: 'running' } ], false, false ],
	modules: {
		'../api': { api: { tick: () => { tickCount++; return new Promise( ( resolve ) => { finish = resolve; } ); }, tasks: async () => ( { tasks: [] } ) } },
		'../format': { TASK_TYPE_LABELS: {}, dateFormat: () => '' },
	},
} );
taskPage.render();
const cleanup = taskPage.effects[1]();
const timer = [ ...taskPage.timers.values() ][0];
const pending = timer(); await timer();
assert.equal( tickCount, 1, 'a pending tick must block a second timer request' );
finish(); await pending;
cleanup(); assert.equal( taskPage.timers.size, 0, 'unmount must release the task timer' );
console.log( '  ok  overlapping ticks blocked and unmount timer cleared' );

const paused = component( 'frontend-src/pages/Tasks.jsx', {
	states: [ [ { id: 1, status: 'paused' } ], false, false ],
	modules: { '../format': { TASK_TYPE_LABELS: {}, dateFormat: () => '' } },
} );
paused.render(); paused.effects[1]();
assert.equal( paused.timers.size, 0, 'paused tasks do not trigger background tick requests' );
console.log( '  ok  paused tasks do not keep ticking' );

const routes = Object.fromEntries( [ 'Dashboard', 'Images', 'Unused', 'Trash', 'Tasks', 'Settings' ].map( ( name ) => [ './pages/' + name, { __esModule: true, default: function Page() {} } ] ) );
for ( const route of [ 'unused', 'trash' ] ) {
	const app = component( 'frontend-src/App.jsx', { modules: routes, hash: '#' + route } );
	const tree = app.render();
	const tabs = tree.children[1].children[0];
	assert.deepEqual( tabs.map( ( tab ) => tab.children[0] ), [ 'Dashboard', 'Images', 'Unused', 'Trash', 'Tasks', 'Settings' ] );
	assert.equal( tree.children[2].children[0].type, routes[ './pages/' + ( route === 'unused' ? 'Unused' : 'Trash' ) ].default );
}
console.log( '  ok  unused and trash have independent tabs and routes' );
console.log( 'UI CONTROL CHECKS PASSED' );
