import { api, formatDate, h, modal } from './util';

const STAGES = [ 'new', 'screening', 'interview', 'offer', 'hired', 'rejected' ];

/**
 * Cards of a stage, sorted.
 *
 * @param {Array}   items   Cards.
 * @param {string}  stage   Stage.
 * @param {boolean} byScore Best match first.
 * @return {Array} Cards.
 */
export function stageCards( items, stage, byScore ) {
	return items
		.filter( ( a ) => a.stage === stage )
		.sort( ( a, b ) => {
			if ( byScore ) {
				const d = ( b.match_score === null ? -1 : b.match_score ) - ( a.match_score === null ? -1 : a.match_score );
				if ( d ) {
					return d;
				}
			}
			return a.position - b.position || a.id - b.id;
		} );
}

/**
 * Optimistic move.
 *
 * @param {Array}  items Cards.
 * @param {number} id    Card.
 * @param {string} stage Stage.
 * @param {number} index Index (-1 end).
 * @return {Array} Cards.
 */
export function moveCard( items, id, stage, index ) {
	const card = items.find( ( a ) => a.id === id );
	if ( ! card ) {
		return items;
	}
	const target = stageCards( items, stage, false ).filter( ( a ) => a.id !== id );
	target.splice( index < 0 || index > target.length ? target.length : index, 0, { ...card, stage } );
	const pos = {};
	target.forEach( ( a, i ) => ( pos[ a.id ] = i + 1 ) );
	return items.map( ( a ) => ( pos[ a.id ] ? { ...a, stage, position: pos[ a.id ] } : a ) );
}

const initials = ( name ) =>
	String( name || '?' )
		.trim()
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( w ) => w.charAt( 0 ).toUpperCase() )
		.join( '' );

/**
 * Employer pipeline app (kanban → stacked list under 720px).
 *
 * @param {HTMLElement} root Mount.
 */
export function initPipeline( root ) {
	const conf = JSON.parse( root.getAttribute( 'data-dev3jb-pipeline' ) || '{}' );
	const i18n = conf.i18n || {};
	const labels = conf.stages || {};
	let items = [];
	let byScore = false;
	let dragId = 0;
	const live = h( 'p', { class: 'dev3jb-sr', role: 'status', 'aria-live': 'polite' } );

	const scoreChip = ( score ) => ( conf.match && score !== null && score !== undefined ? h( 'span', { class: `dev3jb-score dev3jb-score--${ score >= 75 ? 'hi' : score >= 50 ? 'mid' : 'lo' }` }, `${ score }%` ) : null );

	const move = ( id, stage, index ) => {
		const before = items;
		items = moveCard( items, id, stage, index );
		render();
		api( `employer/applications/${ id }`, { method: 'POST', data: { stage, index } } )
			.then( () => ( live.textContent = `${ i18n.moved || '' } ${ labels[ stage ] || '' }` ) )
			.catch( ( e ) => {
				items = before;
				render();
				live.textContent = e.message;
			} );
	};

	const card = ( a, stage, index ) =>
		h(
			'li',
			{
				class: 'dev3jb-pcard',
				draggable: 'true',
				ondragstart: ( e ) => {
					dragId = a.id;
					e.dataTransfer.setData( 'text/plain', String( a.id ) );
					e.dataTransfer.effectAllowed = 'move';
					e.currentTarget.classList.add( 'is-dragging' );
				},
				ondragend: ( e ) => e.currentTarget.classList.remove( 'is-dragging' ),
				ondragover: ( e ) => e.preventDefault(),
				ondrop: ( e ) => {
					e.preventDefault();
					e.stopPropagation();
					const id = Number( e.dataTransfer.getData( 'text/plain' ) ) || dragId;
					if ( id ) {
						move( id, stage, index );
					}
				},
			},
			h(
				'button',
				{ type: 'button', class: 'dev3jb-pcard__open', onclick: () => openDetail( a.id ), 'aria-label': `${ i18n.open || '' }: ${ a.name }` },
				h( 'span', { class: 'dev3jb-pcard__avatar', 'aria-hidden': 'true' }, initials( a.name ) ),
				h(
					'span',
					{ class: 'dev3jb-pcard__body' },
					h( 'span', { class: 'dev3jb-pcard__name' }, a.name ),
					a.headline ? h( 'span', { class: 'dev3jb-pcard__headline' }, a.headline ) : null,
					h( 'span', { class: 'dev3jb-pcard__meta' }, scoreChip( a.match_score ), formatDate( a.created_at ), a.rating ? ` · ${ '★'.repeat( a.rating ) }` : '' )
				)
			),
			h(
				'label',
				{ class: 'dev3jb-pcard__move' },
				h( 'span', { 'aria-hidden': 'true' }, i18n.moveTo || '' ),
				h( 'span', { class: 'dev3jb-sr' }, `${ i18n.moveTo || '' } (${ a.name })` ),
				h(
					'select',
					{ onchange: ( e ) => move( a.id, e.target.value, -1 ) },
					STAGES.map( ( s ) => h( 'option', { value: s, selected: s === a.stage }, labels[ s ] ) )
				)
			)
		);

	const render = () => {
		root.innerHTML = '';
		if ( ! items.length ) {
			root.appendChild( h( 'div', { class: 'dev3jb-empty' }, h( 'p', {}, i18n.none ) ) );
			return;
		}
		const tools = h(
			'div',
			{ class: 'dev3jb-pipetools' },
			h(
				'label',
				{ class: 'dev3jb-check' },
				h( 'input', {
					type: 'checkbox',
					checked: byScore,
					onchange: ( e ) => {
						byScore = e.target.checked;
						render();
					},
				} ),
				h( 'span', {}, i18n.sortScore )
			),
			conf.match ? h( 'button', { type: 'button', class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm', onclick: ( e ) => scoreAll( e.currentTarget ) }, i18n.scoreAll ) : null
		);
		root.appendChild( tools );
		if ( conf.match ) {
			root.appendChild( h( 'p', { class: 'dev3jb-muted dev3jb-small dev3jb-fair' }, i18n.decision ) );
		}
		const board = h( 'div', { class: 'dev3jb-kanban', role: 'list' } );
		STAGES.forEach( ( stage ) => {
			const cards = stageCards( items, stage, byScore );
			const col = h(
				'section',
				{
					class: `dev3jb-kanban__col is-${ stage }`,
					role: 'listitem',
					'aria-label': labels[ stage ],
					ondragover: ( e ) => {
						e.preventDefault();
						col.classList.add( 'is-over' );
					},
					ondragleave: ( e ) => {
						if ( ! col.contains( e.relatedTarget ) ) {
							col.classList.remove( 'is-over' );
						}
					},
					ondrop: ( e ) => {
						e.preventDefault();
						col.classList.remove( 'is-over' );
						const id = Number( e.dataTransfer.getData( 'text/plain' ) ) || dragId;
						if ( id ) {
							move( id, stage, -1 );
						}
					},
				},
				h( 'header', { class: 'dev3jb-kanban__head' }, h( 'h3', {}, labels[ stage ] ), h( 'span', { class: 'dev3jb-kanban__count' }, String( cards.length ) ) ),
				h( 'ul', { class: 'dev3jb-kanban__cards' }, cards.length ? cards.map( ( a, i ) => card( a, stage, i ) ) : h( 'li', { class: 'dev3jb-kanban__empty' }, i18n.empty ) )
			);
			board.appendChild( col );
		} );
		root.appendChild( board );
		root.appendChild( live );
	};

	const load = () =>
		api( `employer/applications?job=${ conf.job }` )
			.then( ( r ) => {
				items = r.items;
				render();
			} )
			.catch( ( e ) => {
				root.innerHTML = '';
				root.appendChild( h( 'div', { class: 'dev3jb-notice dev3jb-notice--error', role: 'alert' }, e.message ) );
			} );

	const scoreAll = ( btn ) => {
		btn.disabled = true;
		btn.textContent = i18n.scoring;
		api( `employer/jobs/${ conf.job }/score`, { method: 'POST', data: { ai: true } } )
			.then( ( r ) => {
				if ( ! r.queue ) {
					return load();
				}
				const poll = () =>
					api( `employer/queue/${ r.queue }` ).then( ( q ) => {
						if ( q.status === 'done' || q.status === 'failed' || q.status === 'cancelled' ) {
							return load();
						}
						btn.textContent = `${ i18n.scoring } ${ q.percent || 0 }%`;
						window.setTimeout( poll, 2000 );
					} );
				return poll();
			} )
			.catch( ( e ) => {
				live.textContent = e.message;
				btn.disabled = false;
				btn.textContent = i18n.scoreAll;
			} );
	};

	const matchBlock = ( app, refresh ) => {
		if ( ! conf.match ) {
			return null;
		}
		const m = app.match;
		const wrap = h( 'section', { class: 'dev3jb-matchbox' }, h( 'h3', { class: 'dev3jb-h' }, i18n.match ) );
		if ( m ) {
			wrap.appendChild( h( 'p', { class: 'dev3jb-matchbox__score' }, h( 'strong', {}, String( m.score ) ), '/100 · ', m.summary ) );
			const crit = h( 'ul', { class: 'dev3jb-crit' } );
			Object.keys( i18n.criteria || {} ).forEach( ( k ) => {
				crit.appendChild( h( 'li', {}, h( 'span', {}, i18n.criteria[ k ] ), h( 'span', { class: 'dev3jb-crit__track' }, h( 'span', { class: 'dev3jb-crit__fill', style: `width:${ m.criteria[ k ] }%` } ) ), h( 'span', { class: 'dev3jb-crit__n' }, String( m.criteria[ k ] ) ) ) );
			} );
			wrap.appendChild( crit );
			if ( m.strengths && m.strengths.length ) {
				wrap.appendChild( h( 'h4', {}, i18n.strengths ) );
				wrap.appendChild( h( 'ul', { class: 'dev3jb-bullets' }, m.strengths.map( ( s ) => h( 'li', {}, s ) ) ) );
			}
			if ( m.gaps && m.gaps.length ) {
				wrap.appendChild( h( 'h4', {}, i18n.gaps ) );
				wrap.appendChild( h( 'ul', { class: 'dev3jb-bullets' }, m.gaps.map( ( s ) => h( 'li', {}, s ) ) ) );
			}
		}
		wrap.appendChild( h( 'p', { class: 'dev3jb-notice dev3jb-notice--info dev3jb-small' }, i18n.decision, ' ', i18n.fairness ) );
		const run = ( ai, btn ) => {
			btn.disabled = true;
			api( `employer/applications/${ app.id }/match`, { method: 'POST', data: { ai } } )
				.then( ( a ) => refresh( a ) )
				.catch( ( e ) => {
					btn.disabled = false;
					live.textContent = e.message;
				} );
		};
		wrap.appendChild(
			h(
				'div',
				{ class: 'dev3jb-row' },
				h( 'button', { type: 'button', class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm', onclick: ( e ) => run( false, e.currentTarget ) }, m ? i18n.recalc : i18n.calc ),
				app.ai ? h( 'button', { type: 'button', class: 'dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm', onclick: ( e ) => run( true, e.currentTarget ) }, i18n.ai ) : null
			)
		);
		return wrap;
	};

	const detailBody = ( app, refresh ) => {
		const p = app.profile;
		const body = h( 'div', { class: 'dev3jb-appdetail' } );
		body.appendChild(
			h(
				'p',
				{ class: 'dev3jb-appdetail__meta' },
				`${ i18n.applied } ${ formatDate( app.created_at ) }`,
				app.email ? h( 'a', { href: `mailto:${ app.email }` }, app.email ) : null,
				app.phone ? h( 'a', { href: `tel:${ app.phone }` }, app.phone ) : null
			)
		);
		// Rating.
		const stars = h( 'div', { class: 'dev3jb-starsel', role: 'group', 'aria-label': i18n.rating } );
		[ 1, 2, 3, 4, 5 ].forEach( ( n ) =>
			stars.appendChild(
				h(
					'button',
					{
						type: 'button',
						'aria-pressed': app.rating >= n ? 'true' : 'false',
						'aria-label': ( i18n.starLabel || '%d' ).replace( '%d', n ),
						onclick: () => api( `employer/applications/${ app.id }`, { method: 'POST', data: { rating: app.rating === n ? 0 : n } } ).then( refresh ),
					},
					'★'
				)
			)
		);
		body.appendChild( stars );
		// Stage.
		if ( app.stage !== 'withdrawn' ) {
			const sel = h( 'select', { id: 'dev3jb-dstage' }, STAGES.map( ( s ) => h( 'option', { value: s, selected: s === app.stage }, labels[ s ] ) ) );
			const notify = h( 'input', { type: 'checkbox', id: 'dev3jb-dnotify' } );
			const msg = h( 'textarea', { id: 'dev3jb-dmsg', rows: '3' } );
			const msgField = h( 'div', { class: 'dev3jb-field', hidden: true }, h( 'label', { for: 'dev3jb-dmsg' }, i18n.message ), msg );
			notify.addEventListener( 'change', () => ( msgField.hidden = ! notify.checked ) );
			body.appendChild(
				h(
					'div',
					{ class: 'dev3jb-box dev3jb-stagebox' },
					h( 'div', { class: 'dev3jb-field' }, h( 'label', { for: 'dev3jb-dstage' }, i18n.stage ), sel ),
					h( 'label', { class: 'dev3jb-check' }, notify, h( 'span', {}, i18n.notify ) ),
					msgField,
					h(
						'button',
						{
							type: 'button',
							class: 'dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm',
							onclick: ( e ) => {
								e.currentTarget.disabled = true;
								api( `employer/applications/${ app.id }`, { method: 'POST', data: { stage: sel.value, notify: notify.checked, message: msg.value } } ).then( ( a ) => {
									items = items.map( ( x ) => ( x.id === a.id ? { ...x, ...a.card } : x ) );
									render();
									refresh( a );
								} );
							},
						},
						i18n.update
					)
				)
			);
		}
		const mb = matchBlock( app, refresh );
		if ( mb ) {
			body.appendChild( mb );
		}
		if ( app.file ) {
			body.appendChild( h( 'p', {}, h( 'a', { class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm', href: app.file.url, target: '_blank', rel: 'noopener' }, `${ i18n.resume }: ${ app.file.name }` ) ) );
		}
		if ( app.cover_letter ) {
			body.appendChild( h( 'h3', { class: 'dev3jb-h' }, i18n.cover ) );
			body.appendChild( h( 'p', { class: 'dev3jb-prewrap' }, app.cover_letter ) );
		}
		if ( p ) {
			body.appendChild( h( 'h3', { class: 'dev3jb-h' }, i18n.profile ) );
			if ( p.headline ) {
				body.appendChild( h( 'p', {}, h( 'strong', {}, p.headline ), p.location ? ` · ${ p.location }` : '', p.years ? ` · ${ p.years } ${ i18n.years }` : '' ) );
			}
			if ( p.summary ) {
				body.appendChild( h( 'p', { class: 'dev3jb-prewrap' }, p.summary ) );
			}
			if ( p.skills && p.skills.length ) {
				body.appendChild( h( 'ul', { class: 'dev3jb-skills' }, p.skills.map( ( s ) => h( 'li', {}, s ) ) ) );
			}
			if ( p.experience && p.experience.length ) {
				body.appendChild( h( 'h4', {}, i18n.experience ) );
				body.appendChild( h( 'ul', { class: 'dev3jb-bullets' }, p.experience.map( ( e ) => h( 'li', {}, h( 'strong', {}, e.title ), e.company ? ` · ${ e.company }` : '', ` (${ e.start } – ${ e.current ? i18n.present : e.end })`, e.summary ? h( 'br' ) : null, e.summary || '' ) ) ) );
			}
			if ( p.education && p.education.length ) {
				body.appendChild( h( 'h4', {}, i18n.education ) );
				body.appendChild( h( 'ul', { class: 'dev3jb-bullets' }, p.education.map( ( e ) => h( 'li', {}, [ e.degree, e.field ].filter( Boolean ).join( ', ' ), e.school ? ` · ${ e.school }` : '', e.year ? ` (${ e.year })` : '' ) ) ) );
			}
		}
		// Notes.
		const note = h( 'textarea', { id: 'dev3jb-dnote', rows: '2' } );
		const events = h( 'ul', { class: 'dev3jb-eventlist' }, ( app.events || [] ).map( ( ev ) => h( 'li', { class: `is-${ ev.kind }` }, h( 'span', {}, ev.body ), h( 'span', { class: 'dev3jb-muted dev3jb-small' }, `${ ev.author ? ev.author + ' · ' : '' }${ formatDate( ev.created_at ) }` ) ) ) );
		body.appendChild( h( 'h3', { class: 'dev3jb-h' }, i18n.notes ) );
		body.appendChild( h( 'div', { class: 'dev3jb-field' }, h( 'label', { for: 'dev3jb-dnote' }, i18n.addNote ), note ) );
		body.appendChild(
			h(
				'button',
				{
					type: 'button',
					class: 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm',
					onclick: () => {
						if ( ! note.value.trim() ) {
							note.focus();
							return;
						}
						api( `employer/applications/${ app.id }/notes`, { method: 'POST', data: { note: note.value } } ).then( ( evs ) => refresh( { ...app, events: evs } ) );
					},
				},
				i18n.save
			)
		);
		body.appendChild( events );
		return body;
	};

	const openDetail = ( id ) => {
		let dlg = null;
		const refresh = ( app ) => {
			if ( ! dlg ) {
				return;
			}
			const scroll = dlg.el.querySelector( '.dev3jb-dialog__body' ).scrollTop;
			const holder = dlg.el.querySelector( '.dev3jb-dialog__body' );
			holder.innerHTML = '';
			holder.appendChild( detailBody( app, refresh ) );
			holder.scrollTop = scroll;
			if ( app.card ) {
				items = items.map( ( x ) => ( x.id === app.id ? { ...x, ...app.card } : x ) );
				render();
			}
		};
		const loading = h( 'p', { class: 'dev3jb-muted' }, '…' );
		const item = items.find( ( x ) => x.id === id );
		dlg = modal( { title: item ? item.name : i18n.candidate, body: loading, size: 'lg' } );
		api( `employer/applications/${ id }` )
			.then( refresh )
			.catch( ( e ) => ( loading.textContent = e.message ) );
	};

	load();
}
