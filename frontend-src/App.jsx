const { useState, useEffect } = wp.element;
const { __ } = wp.i18n;

import Dashboard from './pages/Dashboard';
import Images from './pages/Images';
import Unused from './pages/Unused';
import Tasks from './pages/Tasks';
import Settings from './pages/Settings';

const TABS = [
	{ key: 'dashboard', label: __( 'Dashboard', 'mediasweep' ) },
	{ key: 'images', label: __( 'Images', 'mediasweep' ) },
	{ key: 'unused', label: __( 'Unused & Trash', 'mediasweep' ) },
	{ key: 'tasks', label: __( 'Tasks', 'mediasweep' ) },
	{ key: 'settings', label: __( 'Settings', 'mediasweep' ) },
];

export default function App() {
	const [ tab, setTab ] = useState( () => {
		const hash = window.location.hash.replace( '#', '' );
		return TABS.some( ( t ) => t.key === hash ) ? hash : 'dashboard';
	} );

	useEffect( () => {
		window.location.hash = tab;
	}, [ tab ] );

	const Page =
		tab === 'images' ? Images
		: tab === 'unused' ? Unused
		: tab === 'tasks' ? Tasks
		: tab === 'settings' ? Settings
		: Dashboard;

	return (
		<div className="msw-app">
			<div className="msw-header">
				<h1 className="msw-title">
					<span className="dashicons dashicons-images-alt2" /> MediaSweep
					<span className="msw-version">v{ ( window.mswConfig && window.mswConfig.version ) || '0.1.0' }</span>
				</h1>
				<p className="msw-tagline">
					{ __( 'Local-first media optimization & cleanup for WordPress.', 'mediasweep' ) }
				</p>
			</div>

			<nav className="msw-tabs">
				{ TABS.map( ( t ) => (
					<button
						key={ t.key }
						className={ 'msw-tab' + ( tab === t.key ? ' is-active' : '' ) }
						onClick={ () => setTab( t.key ) }
					>
						{ t.label }
					</button>
				) ) }
			</nav>

			<div className="msw-page">
				<Page go={ setTab } />
			</div>
		</div>
	);
}
