import { api } from './util';

let uid = 1000;

/**
 * Adds a repeater row from its template, optionally filled with values.
 *
 * @param {HTMLElement} repeat Repeater fieldset.
 * @param {Object}      values Values by data-k.
 * @return {HTMLElement} Row.
 */
export function addRow( repeat, values = {} ) {
	const tpl = repeat.querySelector( 'template[data-dev3jb-template]' );
	const rows = repeat.querySelector( '[data-dev3jb-rows]' );
	const i = 'n' + uid++;
	const wrap = document.createElement( 'div' );
	wrap.innerHTML = tpl.innerHTML.replace( /__i__/g, i );
	const row = wrap.firstElementChild;
	Object.keys( values ).forEach( ( k ) => {
		const el = row.querySelector( `[data-k="${ k }"]` );
		if ( ! el ) {
			return;
		}
		if ( el.type === 'checkbox' ) {
			el.checked = !! values[ k ];
		} else {
			el.value = values[ k ] === null || values[ k ] === undefined ? '' : values[ k ];
		}
	} );
	rows.appendChild( row );
	return row;
}

/**
 * Fills the profile form with a parsed resume (nothing is saved until the candidate clicks Save).
 *
 * @param {HTMLFormElement} form    Form.
 * @param {Object}          profile Parsed profile.
 * @param {boolean}         replace Replace existing list rows.
 * @return {string[]} Filled sections.
 */
export function fillProfile( form, profile, replace ) {
	const filled = [];
	[ 'headline', 'summary', 'location' ].forEach( ( k ) => {
		const el = form.querySelector( `[data-dev3jb-p="${ k }"]` );
		if ( el && profile[ k ] && ( replace || ! el.value ) ) {
			el.value = profile[ k ];
			el.classList.add( 'is-filled' );
			filled.push( k );
		}
	} );
	const skills = form.querySelector( '[data-dev3jb-p="skills"]' );
	if ( skills && profile.skills && profile.skills.length ) {
		const have = replace ? [] : skills.value.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
		profile.skills.forEach( ( s ) => {
			if ( ! have.some( ( x ) => x.toLowerCase() === s.toLowerCase() ) ) {
				have.push( s );
			}
		} );
		skills.value = have.join( ', ' );
		skills.classList.add( 'is-filled' );
		filled.push( 'skills' );
	}
	[ 'experience', 'education', 'links' ].forEach( ( key ) => {
		const list = profile[ key ];
		const repeat = form.querySelector( `[data-dev3jb-repeat="${ key }"]` );
		if ( ! repeat || ! list || ! list.length ) {
			return;
		}
		const rows = repeat.querySelector( '[data-dev3jb-rows]' );
		if ( replace ) {
			rows.innerHTML = '';
		}
		list.forEach( ( item ) => addRow( repeat, item ).classList.add( 'is-filled' ) );
		filled.push( key );
	} );
	return filled;
}

/**
 * Candidate profile form: repeaters, unsaved marker, resume upload + parsing.
 *
 * @param {HTMLFormElement} form Form.
 */
export function initProfile( form ) {
	const conf = JSON.parse( form.getAttribute( 'data-dev3jb-profile' ) || '{}' );
	const i18n = conf.i18n || {};
	const unsaved = form.querySelector( '[data-dev3jb-unsaved]' );
	const dirty = () => unsaved && ( unsaved.hidden = false );
	form.addEventListener( 'input', dirty );
	form.addEventListener( 'click', ( e ) => {
		const add = e.target.closest( '[data-dev3jb-add]' );
		if ( add ) {
			const row = addRow( add.closest( '[data-dev3jb-repeat]' ) );
			const first = row.querySelector( 'input,textarea' );
			if ( first ) {
				first.focus();
			}
			dirty();
		}
		const remove = e.target.closest( '[data-dev3jb-remove]' );
		if ( remove ) {
			remove.closest( '[data-dev3jb-row]' ).remove();
			dirty();
		}
	} );

	const input = form.querySelector( '[data-dev3jb-resume-input]' );
	const status = form.querySelector( '[data-dev3jb-resume-status]' );
	const fileId = form.querySelector( '[data-dev3jb-file-id]' );
	const parsed = form.querySelector( '[data-dev3jb-parsed]' );
	const fileName = form.querySelector( '[data-dev3jb-file-name]' );
	if ( ! input ) {
		return;
	}
	const say = ( msg, bad = false ) => {
		status.textContent = msg;
		status.classList.toggle( 'is-error', bad );
	};
	input.addEventListener( 'change', () => {
		const file = input.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const body = new window.FormData();
		body.append( 'file', file );
		input.disabled = true;
		say( i18n.uploading );
		api( 'candidate/resume-file', { method: 'POST', body } )
			.then( ( info ) => {
				fileId.value = info.id;
				if ( fileName ) {
					fileName.textContent = info.name;
				}
				dirty();
				say( i18n.reading );
				return api( 'candidate/parse', { method: 'POST', data: { file_id: info.id } } ).then( ( r ) => {
					const hasData = [ 'headline', 'summary', 'experience' ].some( ( k ) => {
						const el = form.querySelector( `[data-dev3jb-p="${ k }"]` );
						return el && el.value;
					} ) || form.querySelector( '[data-dev3jb-repeat="experience"] [data-dev3jb-row]' );
					const replace = hasData ? window.confirm( i18n.replace ) : true; // eslint-disable-line no-alert
					const filled = fillProfile( form, r.profile || {}, replace );
					parsed.value = info.id;
					const names = filled.map( ( k ) => ( i18n.sections && i18n.sections[ k ] ) || k );
					say( filled.length ? ( i18n.filled || '%s' ).replace( '%s', names.join( ', ' ) ) : i18n.nothing );
					const first = form.querySelector( '.is-filled' );
					if ( first ) {
						first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				} );
			} )
			.catch( ( e ) => say( e.message, true ) )
			.finally( () => {
				input.disabled = false;
				input.value = '';
			} );
	} );
}
