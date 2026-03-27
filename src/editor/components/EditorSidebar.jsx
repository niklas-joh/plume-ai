import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { Sparkles } from 'lucide-react';
import MiniChat from './MiniChat';
import BlockActions from './BlockActions';
import SeoPanel from './SeoPanel';
import '../../styles/tokens.css';
import '../editor.css';

function WpAiMindSidebar() {
	const postId = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostId()
	);
	const [ convId ] = useState( null );

	function handleBlockResult( content, clientId ) {
		if ( ! clientId || ! content ) {
			return;
		}
		// Dispatch block content update via wp.data.
		const { dispatch } = window.wp?.data ?? {};
		if ( dispatch ) {
			dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, {
				content,
			} );
		}
	}

	const panelHeadingStyle = {
		fontSize: 'var(--text-sm)',
		fontWeight: 600,
		color: 'var(--color-text-muted)',
		textTransform: 'uppercase',
		letterSpacing: '0.05em',
		marginBottom: 'var(--space-2)',
		margin: `0 0 var(--space-2) 0`,
	};

	const dividerPanelStyle = {
		borderTop: '1px solid var(--color-border)',
		paddingTop: 'var(--space-3)',
		marginTop: 'var(--space-3)',
	};

	return (
		<>
			<PluginSidebarMoreMenuItem target="wp-ai-mind-sidebar">
				AI Mind
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="wp-ai-mind-sidebar"
				title="AI Mind"
				icon={ <Sparkles size={ 16 } /> }
			>
				<div className="wpaim-editor-sidebar">
					<div className="wpaim-editor-panel">
						<h3 style={ panelHeadingStyle }>Chat</h3>
						<MiniChat postId={ postId } />
					</div>
					<div
						className="wpaim-editor-panel"
						style={ dividerPanelStyle }
					>
						<h3 style={ panelHeadingStyle }>Block Actions</h3>
						<BlockActions
							convId={ convId }
							onResult={ handleBlockResult }
						/>
					</div>
					<div
						className="wpaim-editor-panel"
						style={ dividerPanelStyle }
					>
						<h3 style={ panelHeadingStyle }>SEO</h3>
						<SeoPanel />
					</div>
				</div>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'wp-ai-mind', { render: WpAiMindSidebar } );
