import { ready } from '../frontend/util';
import { initProfile } from '../frontend/profile';

ready( () => {
	document.querySelectorAll( '[data-dev3jb-profile]' ).forEach( initProfile );
} );
