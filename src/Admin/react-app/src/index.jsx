import './styles.scss';
import { createRoot } from '@wordpress/element';
import App from './App';

const mount = document.getElementById( 'sb-admin-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
