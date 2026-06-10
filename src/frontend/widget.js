import { render } from '@wordpress/element';
import FrontendWidget from './FrontendWidget';
import '../styles/tokens.css';
import './widget.css';

// Mount to each widget div on the page (shortcode may appear multiple times)
document.querySelectorAll( '.plume-widget' ).forEach( ( root ) => {
	render( <FrontendWidget />, root );
} );
