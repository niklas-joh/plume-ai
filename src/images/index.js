import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ImagesApp from './ImagesApp';
import '../styles/tokens.css';
import './images.css';

const { nonce } = window.plumeData ?? {};
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'plume-images' );
if ( root ) {
	render( <ImagesApp />, root );
}
