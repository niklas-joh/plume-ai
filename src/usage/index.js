import { render } from '@wordpress/element';
import UsageDashboard from './components/UsageDashboard';
import '../styles/tokens.css';
import './usage.css';

const root = document.getElementById( 'stilus-usage' );
if ( root ) {
	render( <UsageDashboard />, root );
}
