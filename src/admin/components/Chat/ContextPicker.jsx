import { useState, useEffect, useRef } from '@wordpress/element';
import { Search } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';

export default function ContextPicker( { onSelect, onClose } ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const inputRef = useRef( null );
	const timerRef = useRef( null );

	useEffect( () => {
		inputRef.current?.focus();
		return () => clearTimeout( timerRef.current );
	}, [] );

	useEffect( () => {
		clearTimeout( timerRef.current );
		if ( query.length < 2 ) {
			setResults( [] );
			return;
		}
		timerRef.current = setTimeout( async () => {
			setLoading( true );
			try {
				const data = await apiFetch( {
					path: `/wp-ai-mind/v1/search-posts?q=${ encodeURIComponent(
						query
					) }`,
				} );
				setResults( data );
			} catch ( e ) {
				setResults( [] );
			} finally {
				setLoading( false );
			}
		}, 300 );
	}, [ query ] );

	function handleKeyDown( e ) {
		if ( e.key === 'Escape' ) {
			onClose();
		}
	}

	return (
		<div
			className="wpaim-context-picker"
			role="search"
			aria-label="Search post context"
		>
			<div className="wpaim-context-picker__search">
				<Search
					size={ 12 }
					strokeWidth={ 1.5 }
					className="wpaim-context-picker__icon"
				/>
				<input
					ref={ inputRef }
					className="wpaim-context-picker__input"
					placeholder="Search posts, pages, products…"
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
					onKeyDown={ handleKeyDown }
				/>
			</div>
			{ loading && (
				<div
					className="wpaim-context-picker__status"
					aria-live="polite"
				>
					Searching…
				</div>
			) }
			{ ! loading && results.length === 0 && query.length >= 2 && (
				<div
					className="wpaim-context-picker__status"
					aria-live="polite"
				>
					No results
				</div>
			) }
			{ results.length > 0 && (
				<ul className="wpaim-context-picker__list">
					{ results.map( ( post ) => (
						<li key={ post.id }>
							<button
								className="wpaim-context-picker__item"
								type="button"
								onClick={ () => onSelect( post ) }
							>
								<span className="wpaim-context-picker__title">
									{ post.title }
								</span>
								<span className="wpaim-context-picker__badge">
									{ post.type_label }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
