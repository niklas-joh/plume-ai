import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import SeoApp from './SeoApp';
import '../styles/tokens.css';
import './seo.css';

const { nonce } = window.wpAiMindData ?? {};
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'stilus-seo' );
if ( root ) {
	render( <SeoApp />, root );
}
