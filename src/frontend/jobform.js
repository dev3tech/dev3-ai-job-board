import { api, applyFixes, esc, h } from './util';

/**
 * Editor content get/set (TinyMCE when active, else the textarea).
 *
 * @param {string} id Editor id.
 * @return {{ get: Function, set: Function }} Accessors.
 */
export function editor( id ) {
	const mce = () => window.tinymce && window.tinymce.get( id ) && ! window.tinymce.get( id ).isHidden() ? window.tinymce.get( id ) : null;
	const area = () => document.getElementById( id );
	return {
		get: () => ( mce() ? mce().getContent() : ( area() && area().value ) || '' ),
		set: ( html ) => {
			if ( mce() ) {
				mce().setContent( html );
				mce().save();
			}
			if ( area() ) {
				area().value = html;
			}
		},
	};
}

/**
 * Job submission form: company toggle, apply-method fields, writing assistant and wording check.
 *
 * @param {HTMLFormElement} form Form.
 */
export function initJobForm( form ) {
	const ed = editor( 'dev3jb_job_content' );
	const company = form.querySelector( '[data-dev3jb-company]' );
	const newCompany = form.querySelector( '[data-dev3jb-newcompany]' );
	if ( company && newCompany ) {
		const sync = () => ( newCompany.hidden = company.value !== 'new' );
		company.addEventListener( 'change', sync );
		sync();
	}
	const methods = form.querySelector( '[data-dev3jb-applymethod]' );
	const urlField = form.querySelector( '[data-dev3jb-apply-url]' );
	const emailField = form.querySelector( '[data-dev3jb-apply-email]' );
	const syncApply = () => {
		const checked = methods && methods.querySelector( 'input:checked' );
		const v = checked ? checked.value : 'internal';
		if ( urlField ) {
			urlField.hidden = v !== 'url';
		}
		if ( emailField ) {
			emailField.querySelector( 'input' ).required = v === 'email';
		}
	};
	if ( methods ) {
		methods.addEventListener( 'change', syncApply );
		syncApply();
	}

	const assist = form.querySelector( '[data-dev3jb-assist]' );
	if ( ! assist ) {
		return;
	}
	const status = assist.querySelector( '[data-dev3jb-assist-status]' );
	const out = assist.querySelector( '[data-dev3jb-draft-out]' );
	const say = ( msg, bad = false ) => {
		status.textContent = msg;
		status.classList.toggle( 'is-error', bad );
	};
	const val = ( sel ) => {
		const el = form.querySelector( sel );
		return el ? el.value : '';
	};
	const selectedText = ( sel ) => {
		const el = form.querySelector( sel );
		return el && el.value ? el.options[ el.selectedIndex ].text.replace( /^[—\s]+/, '' ) : '';
	};

	const draftBtn = assist.querySelector( '[data-dev3jb-draft]' );
	draftBtn.addEventListener( 'click', () => {
		const workplace = form.querySelector( 'input[name="job[workplace]"]:checked' );
		draftBtn.disabled = true;
		say( assist.getAttribute( 'data-working' ) || '…' );
		api( 'employer/ai/describe', {
			method: 'POST',
			data: {
				title: val( '#dev3jb-title' ),
				notes: val( '[data-dev3jb-notes]' ),
				company_id: company && company.value !== 'new' ? Number( company.value ) : 0,
				company: val( '#dev3jb-c-name' ),
				workplace: workplace ? workplace.value : 'onsite',
				experience: val( '#dev3jb-exp' ),
				types: val( '#dev3jb-type' ) ? [ Number( val( '#dev3jb-type' ) ) ] : [],
				skills: val( '[data-dev3jb-skills]' ),
				location: selectedText( '#dev3jb-loc' ) || val( '#dev3jb-region' ),
				tone: val( '[data-dev3jb-tone]' ) || undefined,
			},
		} )
			.then( ( d ) => {
				say( '' );
				out.hidden = false;
				out.innerHTML = '';
				// d.content is built server-side from escaped text (Describer::to_html).
				out.appendChild( h( 'p', { class: 'dev3jb-draft__title' }, h( 'strong', {}, d.title ) ) );
				out.appendChild( h( 'div', { class: 'dev3jb-prose dev3jb-draft__body', html: d.content } ) );
				if ( d.skills && d.skills.length ) {
					out.appendChild( h( 'p', { class: 'dev3jb-muted dev3jb-small' }, d.skills.join( ', ' ) ) );
				}
				out.appendChild(
					h(
						'div',
						{ class: 'dev3jb-row' },
						h(
							'button',
							{
								type: 'button',
								class: 'dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm',
								onclick: () => {
									ed.set( d.content );
									const title = form.querySelector( '#dev3jb-title' );
									if ( title && d.title ) {
										title.value = d.title;
									}
									const skills = form.querySelector( '[data-dev3jb-skills]' );
									if ( skills && d.skills && d.skills.length ) {
										const have = skills.value.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
										const merged = [ ...have ];
										d.skills.forEach( ( s ) => {
											if ( ! merged.some( ( m ) => m.toLowerCase() === s.toLowerCase() ) ) {
												merged.push( s );
											}
										} );
										skills.value = merged.join( ', ' );
									}
									const exp = form.querySelector( '#dev3jb-exp' );
									if ( exp && ! exp.value && d.experience ) {
										exp.value = d.experience;
									}
									const excerpt = form.querySelector( '[data-dev3jb-excerpt]' );
									if ( excerpt ) {
										excerpt.value = d.excerpt || '';
									}
									out.hidden = true;
									say( assist.getAttribute( 'data-used' ) || '✓' );
								},
							},
							assist.getAttribute( 'data-use' ) || 'Use this'
						),
						h(
							'button',
							{
								type: 'button',
								class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm',
								onclick: () => {
									out.hidden = true;
									out.innerHTML = '';
								},
							},
							assist.getAttribute( 'data-discard' ) || 'Discard'
						)
					)
				);
				out.focus();
			} )
			.catch( ( e ) => say( e.message, true ) )
			.finally( () => ( draftBtn.disabled = false ) );
	} );

	const checkBtn = assist.querySelector( '[data-dev3jb-inclusive]' );
	checkBtn.addEventListener( 'click', () => {
		checkBtn.disabled = true;
		say( '…' );
		api( 'employer/ai/inclusive', { method: 'POST', data: { content: ed.get() } } )
			.then( ( r ) => {
				out.hidden = false;
				out.innerHTML = '';
				if ( ! r.issues.length ) {
					say( assist.getAttribute( 'data-clean' ) || '✓' );
					out.hidden = true;
					return;
				}
				say( '' );
				const list = h( 'ul', { class: 'dev3jb-issues' } );
				r.issues.forEach( ( it ) => {
					const li = h(
						'li',
						{},
						h( 'span', { html: `<del>${ esc( it.phrase ) }</del> → <strong>${ esc( it.suggestion || '–' ) }</strong>` } ),
						h( 'span', { class: 'dev3jb-muted dev3jb-small' }, it.reason ),
						h(
							'button',
							{
								type: 'button',
								class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm',
								onclick: ( e ) => {
									ed.set( applyFixes( ed.get(), [ it ] ) );
									e.currentTarget.disabled = true;
									e.currentTarget.textContent = '✓';
								},
							},
							assist.getAttribute( 'data-replace' ) || 'Replace'
						)
					);
					list.appendChild( li );
				} );
				out.appendChild( list );
			} )
			.catch( ( e ) => say( e.message, true ) )
			.finally( () => ( checkBtn.disabled = false ) );
	} );
}
