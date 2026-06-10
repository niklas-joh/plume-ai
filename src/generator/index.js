import { render } from '@wordpress/element';
import GeneratorWizard from './components/GeneratorWizard';
import '../styles/tokens.css';
import './generator.css';

const root = document.getElementById( 'plume-generator' );
if ( root ) {
	render( <GeneratorWizard />, root );
}
