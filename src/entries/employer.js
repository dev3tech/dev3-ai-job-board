import { ready } from '../frontend/util';
import { initJobForm } from '../frontend/jobform';
import { initPipeline } from '../frontend/pipeline';

ready( () => {
	document.querySelectorAll( '[data-dev3jb-jobform]' ).forEach( initJobForm );
	document.querySelectorAll( '[data-dev3jb-pipeline]' ).forEach( initPipeline );
} );
