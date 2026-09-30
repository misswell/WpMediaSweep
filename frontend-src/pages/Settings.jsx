const { useState, useEffect } = wp.element;
const { Button, Spinner, TextControl, ToggleControl, SelectControl } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';

export default function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		api.settings().then( ( data ) => setSettings( data.settings ) ).catch( ( e ) => {
			setNotice( { type: 'error', text: e.message } );
		} );
	}, [] );

	if ( ! settings ) {
		return (
			<div>
				{ notice && <div className="msw-notice msw-notice-error">{ notice.text }</div> }
				<Spinner />
			</div>
		);
	}

	const set = ( key, value ) => setSettings( { ...settings, [ key ]: value } );

	const save = async () => {
		setBusy( true );
		setNotice( null );
		try {
			const data = await api.saveSettings( settings );
			setSettings( data.settings );
			setNotice( { type: 'success', text: __( 'Settings saved.', 'mediasweep' ) } );
		} catch ( e ) {
			setNotice( { type: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="msw-settings">
			{ notice && <div className={ 'msw-notice msw-notice-' + notice.type }>{ notice.text }</div> }

			<h3 className="msw-section-title">{ __( 'Compression quality', 'mediasweep' ) }</h3>
			<div className="msw-settings-grid">
				<TextControl
					type="number" min="1" max="100"
					label={ __( 'JPEG quality', 'mediasweep' ) }
					value={ settings.jpeg_quality }
					onChange={ ( v ) => set( 'jpeg_quality', v ) }
				/>
				<TextControl
					type="number" min="1" max="100"
					label={ __( 'WebP quality', 'mediasweep' ) }
					value={ settings.webp_quality }
					onChange={ ( v ) => set( 'webp_quality', v ) }
				/>
				<TextControl
					type="number" min="1" max="100"
					label={ __( 'AVIF quality', 'mediasweep' ) }
					value={ settings.avif_quality }
					onChange={ ( v ) => set( 'avif_quality', v ) }
				/>
				<ToggleControl
					label={ __( 'PNG lossless only', 'mediasweep' ) }
					help={ __( 'When off, PNG images may also be palette-reduced (smaller, slightly lossy).', 'mediasweep' ) }
					checked={ settings.png_lossless }
					onChange={ ( v ) => set( 'png_lossless', v ) }
				/>
			</div>

			<h3 className="msw-section-title">{ __( 'Backups', 'mediasweep' ) }</h3>
			<div className="msw-settings-grid">
				<ToggleControl
					label={ __( 'Keep original files', 'mediasweep' ) }
					help={ __( 'Saves a .ms-original copy next to each compressed image so it can be restored anytime.', 'mediasweep' ) }
					checked={ settings.keep_originals }
					onChange={ ( v ) => set( 'keep_originals', v ) }
				/>
				<TextControl
					type="number" min="0" max="3650"
					label={ __( 'Backup retention (days, 0 = forever)', 'mediasweep' ) }
					value={ settings.backup_retention }
					onChange={ ( v ) => set( 'backup_retention', v ) }
				/>
			</div>

			<h3 className="msw-section-title">{ __( 'Scanning', 'mediasweep' ) }</h3>
			<div className="msw-settings-grid">
				<ToggleControl
					label={ __( 'Scan theme files for references', 'mediasweep' ) }
					checked={ settings.scan_themes }
					onChange={ ( v ) => set( 'scan_themes', v ) }
				/>
				<ToggleControl
					label={ __( 'Scan plugin files for references', 'mediasweep' ) }
					checked={ settings.scan_plugins }
					onChange={ ( v ) => set( 'scan_plugins', v ) }
				/>
			</div>

			<h3 className="msw-section-title">{ __( 'Performance & cleanup', 'mediasweep' ) }</h3>
			<div className="msw-settings-grid">
				<TextControl
					type="number" min="10" max="1000"
					label={ __( 'Batch size (files per step)', 'mediasweep' ) }
					value={ settings.batch_size }
					onChange={ ( v ) => set( 'batch_size', v ) }
				/>
				<TextControl
					type="number" min="5" max="120"
					label={ __( 'Time budget per step (seconds)', 'mediasweep' ) }
					value={ settings.time_budget }
					onChange={ ( v ) => set( 'time_budget', v ) }
				/>
				<TextControl
					type="number" min="1" max="365"
					label={ __( 'Trash retention (days)', 'mediasweep' ) }
					value={ settings.trash_retention }
					onChange={ ( v ) => set( 'trash_retention', v ) }
				/>
			</div>

			<p>
				<Button variant="primary" onClick={ save } disabled={ busy }>
					{ busy ? __( 'Saving…', 'mediasweep' ) : __( 'Save settings', 'mediasweep' ) }
				</Button>
			</p>

			<p className="msw-page-hint">
				{ __( 'MediaSweep processes everything on your own server. No image data ever leaves this site.', 'mediasweep' ) }
			</p>
		</div>
	);
}
