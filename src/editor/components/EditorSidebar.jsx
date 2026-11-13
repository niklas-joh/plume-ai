import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Sparkles } from 'lucide-react';
import MiniChat from './MiniChat';
import BlockActions from './BlockActions';
import SeoPanel from './SeoPanel';
import '../../styles/tokens.css';
import '../editor.css';

/**
 * Block Editor plugin sidebar containing the mini chat, block actions, and SEO panels.
 *
 * Registered via `registerPlugin` so it appears in the editor's plugin sidebar
 * list. BlockActions writes its results to the block itself.
 *
 * @return {ReactElement}
 */
function PlumeSidebar() {
	const postId = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostId()
	);

	return (
		<>
			<PluginSidebarMoreMenuItem target="plume-sidebar">
				{ __( 'Plume AI', 'plume' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="plume-sidebar"
				title={ __( 'Plume AI', 'plume' ) }
				icon={ <Sparkles size={ 16 } /> }
			>
				<div className="plume-editor-sidebar">
					<div className="plume-editor-panel">
						<h3 className="plume-panel__heading">
							{ __( 'Chat', 'plume' ) }
						</h3>
						<MiniChat postId={ postId } />
					</div>
					<div className="plume-editor-panel">
						<h3 className="plume-panel__heading">
							{ __( 'Block actions', 'plume' ) }
						</h3>
						<BlockActions />
					</div>
					<div className="plume-editor-panel">
						<h3 className="plume-panel__heading">
							{ __( 'SEO', 'plume' ) }
						</h3>
						<SeoPanel />
					</div>
				</div>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'plume', { render: PlumeSidebar } );
