import { useState, useEffect } from '@wordpress/element';
import { TabPanel, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { Settings } from 'lucide-react';
import ProvidersTab from './ProvidersTab';
import VoiceTab from './VoiceTab';
import FeaturesTab from './FeaturesTab';

const TABS = [
	{ name: 'providers', title: 'Providers' },
	{ name: 'voice', title: 'Voice' },
	{ name: 'features', title: 'Features' },
];

export default function SettingsApp() {
	const [ settings, setSettings ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveResult, setSaveResult ] = useState( null ); // 'success' | 'error' | null

	useEffect( () => {
		apiFetch( { path: '/wp-ai-mind/v1/settings' } )
			.then( setSettings )
			.catch( () => {} );
	}, [] );

	async function saveSettings( patch ) {
		setIsSaving( true );
		setSaveResult( null );
		try {
			await apiFetch( {
				path: '/wp-ai-mind/v1/settings',
				method: 'POST',
				data: patch,
			} );
			setSettings( ( prev ) => ( { ...prev, ...patch } ) );
			setSaveResult( 'success' );
		} catch ( e ) {
			setSaveResult( 'error' );
		} finally {
			setIsSaving( false );
		}
	}

	const handleRunSetup = async () => {
		const data = window.wpAiMindData ?? {};
		await window.fetch( `${ data.restUrl ?? '' }/onboarding`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.nonce ?? '',
			},
			body: JSON.stringify( { seen: false } ),
		} );
		window.location.href = 'admin.php?page=wp-ai-mind';
	};

	const tabProps = { settings, saveSettings, isSaving };

	return (
		<div className="wpaim-settings-shell">
			<div className="wpaim-settings-header">
				<div className="wpaim-settings-title">
					<Settings size={ 16 } />
					<span>WP AI Mind — Settings</span>
				</div>

				{ saveResult && (
					<Notice
						status={
							saveResult === 'success' ? 'success' : 'error'
						}
						isDismissible
						onRemove={ () => setSaveResult( null ) }
					>
						{ saveResult === 'success'
							? 'Saved successfully'
							: 'Save failed — please try again' }
					</Notice>
				) }
			</div>

			<TabPanel tabs={ TABS } className="wpaim-settings-tabpanel">
				{ ( tab ) => {
					if ( settings === null ) {
						return (
							<div className="wpaim-settings-loading">
								Loading settings…
							</div>
						);
					}
					if ( tab.name === 'providers' ) {
						return <ProvidersTab { ...tabProps } />;
					}
					if ( tab.name === 'voice' ) {
						return <VoiceTab { ...tabProps } />;
					}
					if ( tab.name === 'features' ) {
						return <FeaturesTab { ...tabProps } />;
					}
				} }
			</TabPanel>

			<div
				className="wpaim-settings-section"
				style={ {
					borderTop: '1px solid var(--color-border-subtle)',
					paddingTop: 'var(--space-4)',
					marginTop: 'var(--space-4)',
				} }
			>
				<div className="wpaim-settings-label">Setup</div>
				<p className="wpaim-settings-description">
					Re-run the onboarding wizard to change your API connection
					or provider settings.
				</p>
				<button
					type="button"
					className="wpaim-btn wpaim-btn--secondary"
					onClick={ handleRunSetup }
				>
					Run setup again
				</button>
			</div>
		</div>
	);
}
