import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge } from '../core3/ui';
import { STAGES, ScoreBadge, formatDate, initials } from './shared';

/**
 * Sorted cards of a stage.
 *
 * @param {Array}   items   Cards.
 * @param {string}  stage   Stage.
 * @param {boolean} byScore Sort by match score.
 * @return {Array} Cards.
 */
export function stageItems( items, stage, byScore ) {
	const list = items.filter( ( a ) => a.stage === stage );
	return list.sort( ( a, b ) => {
		if ( byScore ) {
			const sa = a.match_score === null ? -1 : a.match_score;
			const sb = b.match_score === null ? -1 : b.match_score;
			if ( sa !== sb ) {
				return sb - sa;
			}
		}
		return a.position - b.position || a.id - b.id;
	} );
}

/**
 * Applies a move locally (optimistic update).
 *
 * @param {Array}  items Cards.
 * @param {number} id    Card id.
 * @param {string} stage Target stage.
 * @param {number} index Target index (-1 = end).
 * @return {Array} New cards.
 */
export function moveLocal( items, id, stage, index ) {
	const card = items.find( ( a ) => a.id === id );
	if ( ! card ) {
		return items;
	}
	const target = stageItems( items, stage, false ).filter( ( a ) => a.id !== id );
	const at = index < 0 || index > target.length ? target.length : index;
	target.splice( at, 0, { ...card, stage } );
	const positions = {};
	target.forEach( ( a, i ) => {
		positions[ a.id ] = i + 1;
	} );
	return items.map( ( a ) => ( positions[ a.id ] ? { ...a, stage, position: positions[ a.id ] } : a ) );
}

/**
 * Kanban board: drag and drop between columns, or use the "Move to" select (keyboard and touch).
 * Below 720px the board becomes a stacked, stage-grouped list.
 *
 * @param {Object}   props           Props.
 * @param {Array}    props.items     Cards.
 * @param {Object}   props.labels    Stage labels.
 * @param {boolean}  props.byScore   Sort by score.
 * @param {Function} props.onMove    ( id, stage, index ) => void.
 * @param {Function} props.onOpen    ( id ) => void.
 * @param {boolean}  props.showScore Show match scores.
 * @return {Element} Board.
 */
export default function Pipeline( { items, labels, byScore = false, onMove, onOpen, showScore = true } ) {
	const [ dragId, setDragId ] = useState( null );
	const [ over, setOver ] = useState( null );

	const drop = ( e, stage, index ) => {
		e.preventDefault();
		e.stopPropagation();
		const id = Number( e.dataTransfer.getData( 'text/plain' ) ) || dragId;
		setDragId( null );
		setOver( null );
		if ( id ) {
			onMove( id, stage, index );
		}
	};

	return (
		<div className="dev3jb-kanban" role="list" aria-label={ __( 'Application stages', 'dev3-ai-job-board' ) }>
			{ STAGES.map( ( stage ) => {
				const cards = stageItems( items, stage, byScore );
				return (
					<section
						key={ stage }
						role="listitem"
						className={ `dev3jb-kanban__col is-${ stage }${ over === stage ? ' is-over' : '' }` }
						aria-labelledby={ `dev3jb-col-${ stage }` }
						onDragOver={ ( e ) => {
							e.preventDefault();
							setOver( stage );
						} }
						onDragLeave={ ( e ) => {
							if ( ! e.currentTarget.contains( e.relatedTarget ) ) {
								setOver( null );
							}
						} }
						onDrop={ ( e ) => drop( e, stage, -1 ) }
					>
						<header className="dev3jb-kanban__head">
							<h3 id={ `dev3jb-col-${ stage }` }>{ labels[ stage ] }</h3>
							<span className="dev3jb-kanban__count">{ cards.length }</span>
						</header>
						<ul className="dev3jb-kanban__cards">
							{ cards.length === 0 && <li className="dev3jb-kanban__empty">{ __( 'Drop applications here', 'dev3-ai-job-board' ) }</li> }
							{ cards.map( ( a, i ) => (
								<li
									key={ a.id }
									className={ `dev3jb-kcard${ dragId === a.id ? ' is-dragging' : '' }` }
									draggable
									onDragStart={ ( e ) => {
										e.dataTransfer.setData( 'text/plain', String( a.id ) );
										e.dataTransfer.effectAllowed = 'move';
										setDragId( a.id );
									} }
									onDragEnd={ () => {
										setDragId( null );
										setOver( null );
									} }
									onDragOver={ ( e ) => e.preventDefault() }
									onDrop={ ( e ) => drop( e, stage, i ) }
								>
									<button type="button" className="dev3jb-kcard__open" onClick={ () => onOpen( a.id ) }>
										<span className="dev3jb-kcard__avatar" aria-hidden="true">
											{ initials( a.name ) }
										</span>
										<span className="dev3jb-kcard__body">
											<span className="dev3jb-kcard__name">{ a.name }</span>
											{ a.headline && <span className="dev3jb-kcard__headline">{ a.headline }</span> }
											<span className="dev3jb-kcard__meta">
												{ showScore && <ScoreBadge score={ a.match_score } /> }
												{ formatDate( a.created_at ) }
												{ a.rating > 0 && <span aria-label={ sprintf( /* translators: %d: stars */ __( '%d of 5 stars', 'dev3-ai-job-board' ), a.rating ) }>{ '★'.repeat( a.rating ) }</span> }
												{ a.has_file && <Badge>{ __( 'Resume', 'dev3-ai-job-board' ) }</Badge> }
											</span>
										</span>
									</button>
									<label className="dev3jb-kcard__move">
										<span aria-hidden="true">{ __( 'Move to', 'dev3-ai-job-board' ) }</span>
										<span className="screen-reader-text">{ sprintf( /* translators: %s: candidate */ __( 'Move %s to', 'dev3-ai-job-board' ), a.name ) }</span>
										<select value={ a.stage } onChange={ ( e ) => onMove( a.id, e.target.value, -1 ) }>
											{ STAGES.map( ( s ) => (
												<option key={ s } value={ s }>
													{ labels[ s ] }
												</option>
											) ) }
										</select>
									</label>
								</li>
							) ) }
						</ul>
					</section>
				);
			} ) }
		</div>
	);
}
