import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import SeoApp from './SeoApp';
import '../styles/tokens.css';
import './seo.css';

const { nonce } = window.plumeData ?? {};
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'plume-seo' );
if ( root ) {
	render( <SeoApp />, root );
}
