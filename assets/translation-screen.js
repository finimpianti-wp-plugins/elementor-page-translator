/**
 * Translation screen (Elementor Page Translator).
 *
 * Plain JavaScript, no build step. Data comes from the REST API (ept/v1);
 * wp.apiFetch adds the X-WP-Nonce header. All markup is built with DOM APIs
 * and textContent: texts coming from the page are never injected as HTML.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const config = window.eptScreen || {};
	const app = document.getElementById( 'ept-app' );
	if ( ! app ) {
		return;
	}

	const LONG_TEXT = 80;
	let data = null;
	let dirty = false;
	let slugTouched = false;
	let onlyTodo = false;
	let busy = false;
	let allSame = false;
	let bulkBackup = null; // key => previous choice, while "all same as original" is checked
	const rowState = {}; // key => { t, s, ok }
	const linkState = {}; // key => { mode: translate|keep|manual, url }

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ name, value ] ) => {
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( name === 'text' ) {
				node.textContent = value;
			} else if ( name === 'className' ) {
				node.className = value;
			} else if ( name.startsWith( 'on' ) ) {
				node.addEventListener( name.slice( 2 ).toLowerCase(), value );
			} else if ( value === true ) {
				node.setAttribute( name, '' );
			} else {
				node.setAttribute( name, String( value ) );
			}
		} );
		( children || [] ).forEach( ( child ) => {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function path( suffix ) {
		return config.restPath + config.postId + '/' + config.lang + ( suffix || '' );
	}

	function slugify( text ) {
		return String( text )
			.replace( /<[^>]*>/g, '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	}

	function tagCounts( html ) {
		const counts = {};
		( String( html ).match( /<\s*\/?\s*[a-z][a-z0-9-]*\b[^>]*>/gi ) || [] ).forEach( ( tag ) => {
			const name = tag.replace( /^<\s*(\/?)\s*([a-z][a-z0-9-]*).*$/is, '$1$2' ).toLowerCase();
			counts[ name ] = ( counts[ name ] || 0 ) + 1;
		} );
		return JSON.stringify( Object.keys( counts ).sort().map( ( k ) => [ k, counts[ k ] ] ) );
	}

	function setDirty( value ) {
		dirty = value;
		const marker = document.getElementById( 'ept-dirty' );
		if ( marker ) {
			marker.hidden = ! value;
		}
	}

	function notice( type, message, links ) {
		const box = document.getElementById( 'ept-notices' );
		if ( ! box ) {
			return;
		}
		const p = el( 'p', {}, [ message ] );
		( links || [] ).forEach( ( link ) => {
			p.appendChild( document.createTextNode( ' ' ) );
			p.appendChild( el( 'a', { href: link.href, text: link.text, target: link.blank ? '_blank' : null, rel: link.blank ? 'noopener' : null } ) );
		} );
		box.replaceChildren( el( 'div', { className: 'notice notice-' + type + ' inline' }, [ p ] ) );
		box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function isDone( row ) {
		const st = rowState[ row.key ];
		return row.dynamic || st.s === 'same' || st.s === 'excluded' || st.t.trim() !== '';
	}

	/* ------------------------------------------------------------------ */
	/* Load and render                                                      */
	/* ------------------------------------------------------------------ */

	function load() {
		wp.apiFetch( { path: path() } )
			.then( ( response ) => {
				setData( response );
				render();
				if ( response.deepl && response.deepl.warning ) {
					notice( 'warning', response.deepl.warning );
				}
				const empty = response.strings.filter( ( row ) => ! row.dynamic && row.state === 'new' && row.translation === '' && row.status === '' && row.format !== 'code' );
				if ( empty.length && ( response.deepl.configured || empty.some( ( row ) => row.suggestion ) ) ) {
					propose( [], false );
				}
			} )
			.catch( ( error ) => {
				app.replaceChildren( el( 'div', { className: 'notice notice-error' }, [ el( 'p', { text: error.message || __( 'Errore di caricamento.', 'ept' ) } ) ] ) );
			} );
	}

	function setData( response ) {
		data = response;
		data.strings.forEach( ( row ) => {
			// Only "same" and "excluded" are row choices; "translated" is implied by a non-empty text.
			const choice = row.status === 'same' || row.status === 'excluded' ? row.status : '';
			rowState[ row.key ] = {
				t: row.translation,
				s: choice,
				ok: row.state !== 'modified',
				simple: !! row.simple,
				plain: row.simple ? row.simple.translation : '',
				p: row.proposal || '', // unconfirmed automatic proposal: memory | filter | deepl
			};
		} );
		( data.links || [] ).forEach( ( link ) => {
			linkState[ link.key ] = { mode: link.mode, url: link.manual };
		} );
		slugTouched = data.page.slug !== '';
		allSame = false;
		bulkBackup = null;
		setDirty( false );
	}

	function render() {
		app.replaceChildren( ...[
			el( 'div', { id: 'ept-notices' } ),
			renderRelink(),
			renderHeader(),
			renderToolbar(),
			renderStrings(),
			renderLinks(),
			renderFooter()
		].filter( Boolean ) );
		updateProgress();
		applyFilter();
	}

	function renderHeader() {
		const page = data.page;
		const header = el( 'div', { className: 'ept-header' } );

		// Source and language.
		const langSelect = el( 'select', {
			id: 'ept-lang',
			onChange: ( event ) => {
				if ( dirty && ! window.confirm( __( 'Ci sono modifiche non salvate. Cambiare lingua comunque?', 'ept' ) ) ) {
					event.target.value = config.lang;
					return;
				}
				setDirty( false );
				window.location.href = config.screenUrl + '&post=' + config.postId + '&lang=' + encodeURIComponent( event.target.value );
			},
		} );
		data.languages.forEach( ( lang ) => {
			langSelect.appendChild( el( 'option', { value: lang.code, selected: lang.code === config.lang, text: lang.name + ( lang.exists ? ' ✓' : '' ) } ) );
		} );

		header.appendChild(
			el( 'p', { className: 'ept-source' }, [
				__( 'Pagina originale:', 'ept' ) + ' ',
				el( 'a', { href: data.source.edit_link, text: data.source.title } ),
				' · ',
				el( 'label', { for: 'ept-lang', text: __( 'Lingua', 'ept' ) + ' ' } ),
				langSelect,
				data.lang.public ? null : el( 'span', { className: 'ept-tag ept-tag--warn', text: __( 'lingua non pubblica', 'ept' ) } ),
			] )
		);

		const grid = el( 'div', { className: 'ept-header-grid' } );

		// Title.
		const title = el( 'input', {
			type: 'text',
			id: 'ept-title',
			className: 'regular-text',
			value: page.title,
			placeholder: data.source.title,
			onInput: ( event ) => {
				page.title = event.target.value;
				if ( ! slugTouched && ! data.source.is_home ) {
					page.slug = slugify( page.title );
					document.getElementById( 'ept-slug' ).value = page.slug;
				}
				document.getElementById( 'ept-slug' ).placeholder = slugify( page.title || data.source.title );
				updateUrl();
				setDirty( true );
			},
		} );
		grid.appendChild( field( 'ept-title', __( 'Titolo della pagina', 'ept' ), title, data.source.title ) );

		// Slug.
		const slug = el( 'input', {
			type: 'text',
			id: 'ept-slug',
			value: page.slug,
			placeholder: slugify( page.title || data.source.title ),
			disabled: data.source.is_home,
			onInput: ( event ) => {
				page.slug = event.target.value;
				slugTouched = true;
				updateUrl();
				setDirty( true );
			},
		} );
		grid.appendChild( field( 'ept-slug', __( 'Slug', 'ept' ), slug, data.source.is_home ? __( 'La home della lingua è la pagina radice: lo slug è il prefisso.', 'ept' ) : data.source.slug ) );

		// Parent and URL preview.
		const parentBox = el( 'div', { className: 'ept-parent' } );
		if ( data.parent.ok ) {
			parentBox.appendChild( el( 'span', { text: data.parent.title } ) );
		}
		if ( data.parent.message ) {
			parentBox.appendChild( el( 'p', { className: 'ept-warning', text: data.parent.message } ) );
		}
		if ( data.parent.needs_choice || page.parent_mode === 'root' ) {
			parentBox.appendChild(
				el( 'label', {}, [
					el( 'input', {
						type: 'checkbox',
						checked: page.parent_mode === 'root',
						onChange: ( event ) => {
							page.parent_mode = event.target.checked ? 'root' : '';
							save( false );
						},
					} ),
					' ' + __( 'Appoggia alla pagina radice della lingua', 'ept' ),
				] )
			);
		}
		grid.appendChild( field( null, __( 'Genitore', 'ept' ), parentBox ) );
		grid.appendChild( field( null, __( 'Indirizzo', 'ept' ), el( 'code', { id: 'ept-url' } ) ) );

		// Status.
		if ( data.target ) {
			const links = el( 'span', {}, [
				el( 'span', { text: data.target.status === 'publish' ? __( 'Pubblicata', 'ept' ) : __( 'Bozza', 'ept' ) } ),
				' · ',
				el( 'a', { href: data.target.view_link, target: '_blank', rel: 'noopener', text: __( 'Visualizza', 'ept' ) } ),
				' · ',
				el( 'a', { href: data.target.elementor_link, text: __( 'Apri in Elementor', 'ept' ) } ),
			] );
			if ( data.target.state === 'outdated' ) {
				links.appendChild( el( 'span', { className: 'ept-tag ept-tag--warn', text: __( 'da aggiornare', 'ept' ) } ) );
			}
			if ( data.target.incomplete > 0 ) {
				links.appendChild( el( 'span', { className: 'ept-tag ept-tag--warn', text: __( 'incompleta', 'ept' ) } ) );
			}
			if ( data.target.manual_changes ) {
				links.appendChild( el( 'span', { className: 'ept-tag ept-tag--warn', text: __( 'modificata a mano in Elementor', 'ept' ) } ) );
			}
			grid.appendChild( field( null, __( 'Pagina tradotta', 'ept' ), links ) );
		} else {
			const status = el( 'select', {
				id: 'ept-status',
				onChange: ( event ) => {
					page.status = event.target.value;
					setDirty( true );
				},
			} );
			status.appendChild( el( 'option', { value: 'draft', selected: page.status !== 'publish', text: __( 'Bozza', 'ept' ) } ) );
			if ( data.can_publish ) {
				status.appendChild( el( 'option', { value: 'publish', selected: page.status === 'publish', text: __( 'Pubblicata', 'ept' ) } ) );
			}
			grid.appendChild( field( 'ept-status', __( 'Stato alla creazione', 'ept' ), status ) );
		}

		grid.appendChild( field( null, __( 'Avanzamento', 'ept' ), el( 'span', {}, [ el( 'progress', { id: 'ept-progress-bar', max: 1, value: 0 } ), ' ', el( 'span', { id: 'ept-progress' } ) ] ) ) );
		header.appendChild( grid );
		setTimeout( updateUrl, 0 );
		return header;
	}

	function field( id, label, control, help ) {
		return el( 'div', { className: 'ept-field' }, [
			id ? el( 'label', { for: id, text: label } ) : el( 'span', { className: 'ept-field-label', text: label } ),
			control,
			help ? el( 'span', { className: 'description', text: help } ) : null,
		] );
	}

	function updateUrl() {
		const target = document.getElementById( 'ept-url' );
		if ( ! target ) {
			return;
		}
		if ( ! data.parent.ok ) {
			target.textContent = '—';
			return;
		}
		const base = data.parent.url.replace( /\/?$/, '/' );
		target.textContent = data.source.is_home ? base : base + ( data.page.slug || slugify( data.page.title || data.source.title ) ) + '/';
	}

	function renderToolbar() {
		return el( 'div', { className: 'ept-toolbar' }, [
			el( 'label', {}, [
				el( 'input', {
					type: 'checkbox',
					checked: onlyTodo,
					onChange: ( event ) => {
						onlyTodo = event.target.checked;
						applyFilter();
					},
				} ),
				' ' + __( 'Mostra solo i testi da tradurre', 'ept' ),
			] ),
			el( 'label', {}, [
				el( 'input', { type: 'checkbox', id: 'ept-all-same', checked: allSame, onChange: ( event ) => setAllSame( event.target.checked ) } ),
				' ' + __( 'Tutti i testi uguali all\'originale', 'ept' ),
			] ),
			el( 'button', { type: 'button', className: 'button', id: 'ept-copy', text: __( 'Copia testi da tradurre', 'ept' ), onClick: copyTexts } ),
			el( 'button', { type: 'button', className: 'button', id: 'ept-paste', text: __( 'Incolla traduzioni', 'ept' ), onClick: openPaste } ),
			el( 'button', { type: 'button', className: 'button', id: 'ept-propose', text: data.deepl.configured ? __( 'Proponi traduzioni', 'ept' ) : __( 'Proponi dalla memoria', 'ept' ), onClick: () => propose( [], false ) } ),
			el( 'button', { type: 'button', className: 'button', id: 'ept-accept-all', text: __( 'Accetta tutte le proposte', 'ept' ), onClick: acceptAll } ),
			el( 'span', { id: 'ept-dirty', className: 'ept-tag ept-tag--warn', hidden: ! dirty, text: __( 'Modifiche non salvate', 'ept' ) } ),
		] );
	}

	function proposalLabel( source ) {
		if ( source === 'deepl' ) {
			return __( 'Proposta DeepL da rivedere', 'ept' );
		}
		if ( source === 'memory' ) {
			return __( 'Proposta dalla memoria da rivedere', 'ept' );
		}
		if ( source === 'paste' ) {
			return __( 'Traduzione incollata da rivedere', 'ept' );
		}
		return __( 'Proposta automatica da rivedere', 'ept' );
	}

	/* ------------------------------------------------------------------ */
	/* Copy / paste with a free web translator (no account, no key)        */
	/* ------------------------------------------------------------------ */

	let copyList = null; // [ key | '__title' ] in the order of the numbers of the last copy

	function rowsToTranslate() {
		return data.strings.filter( ( row ) => {
			const st = rowState[ row.key ];
			return ! row.dynamic && row.format !== 'code' && st.s === '' && st.t.trim() === '';
		} );
	}

	function googleLang( locale ) {
		const parts = String( locale ).split( '_' );
		if ( parts[ 0 ] === 'zh' ) {
			return parts[ 1 ] === 'TW' || parts[ 1 ] === 'HK' ? 'zh-TW' : 'zh-CN';
		}
		return parts[ 0 ];
	}

	function copyTexts() {
		const rows = rowsToTranslate();
		copyList = [];
		const blocks = [];
		if ( ! data.page.title ) {
			copyList.push( '__title' );
			blocks.push( '[' + copyList.length + ']\n' + data.source.title );
		}
		rows.forEach( ( row ) => {
			copyList.push( row.key );
			blocks.push( '[' + copyList.length + ']\n' + ( rowState[ row.key ].simple ? row.simple.original : row.original ) );
		} );
		if ( ! blocks.length ) {
			notice( 'info', __( 'Non ci sono testi da tradurre: tutti i campi sono già compilati.', 'ept' ) );
			return;
		}
		const text = blocks.join( '\n\n' );
		const url = 'https://translate.google.com/?sl=' + encodeURIComponent( googleLang( data.source_locale || 'it_IT' ) ) + '&tl=' + encodeURIComponent( googleLang( data.lang.locale ) ) + '&op=translate';
		const links = [ { href: url, text: __( 'Apri Google Traduttore', 'ept' ), blank: true } ];
		const done = () => notice( 'success', sprintf( _n( '%d testo copiato negli appunti. Incollalo nel traduttore, copia il risultato e usa "Incolla traduzioni". Lascia invariati i numeri tra parentesi quadre.', '%d testi copiati negli appunti. Incollali nel traduttore, copia il risultato e usa "Incolla traduzioni". Lascia invariati i numeri tra parentesi quadre.', blocks.length, 'ept' ), blocks.length ), links );
		const fallback = () => {
			notice( 'info', __( 'Copia questo testo (Ctrl+C / Cmd+C), incollalo nel traduttore, poi usa "Incolla traduzioni". Lascia invariati i numeri tra parentesi quadre.', 'ept' ), links );
			const area = el( 'textarea', { className: 'large-text ept-code', rows: 10, readonly: true } );
			area.value = text;
			document.getElementById( 'ept-notices' ).appendChild( area );
			area.focus();
			area.select();
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function openPaste() {
		const box = document.getElementById( 'ept-notices' );
		const area = el( 'textarea', { className: 'large-text', rows: 10, id: 'ept-paste-area', 'aria-label': __( 'Testo tradotto da incollare', 'ept' ) } );
		box.replaceChildren(
			el( 'div', { className: 'ept-paste notice notice-info inline' }, [
				el( 'p', { text: __( 'Incolla qui il testo tradotto, con i numeri tra parentesi quadre ([1], [2]…). Ogni traduzione andrà nel suo campo come proposta da rivedere.', 'ept' ) } ),
				area,
				el( 'p', {}, [
					el( 'button', { type: 'button', className: 'button button-primary', text: __( 'Inserisci le traduzioni', 'ept' ), onClick: () => applyPaste( area.value ) } ),
					' ',
					el( 'button', { type: 'button', className: 'button', text: __( 'Annulla', 'ept' ), onClick: () => box.replaceChildren() } ),
				] ),
			] )
		);
		area.focus();
	}

	function applyPaste( text ) {
		if ( ! copyList ) {
			// Same rule as the copy: rebuilt after a reload (the list is deterministic).
			copyList = ( data.page.title ? [] : [ '__title' ] ).concat( rowsToTranslate().map( ( row ) => row.key ) );
		}
		const parts = String( text ).split( /\[\s*(\d+)\s*\]/ );
		const found = {};
		for ( let i = 1; i < parts.length; i += 2 ) {
			found[ parseInt( parts[ i ], 10 ) ] = parts[ i + 1 ].replace( /^\s*\n/, '' ).replace( /\s+$/, '' ).trim();
		}
		const byKey = {};
		data.strings.forEach( ( row ) => {
			byKey[ row.key ] = row;
		} );
		let applied = 0;
		const missing = [];
		copyList.forEach( ( key, index ) => {
			const translation = found[ index + 1 ];
			if ( translation === undefined || translation === '' ) {
				missing.push( index + 1 );
				return;
			}
			if ( key === '__title' ) {
				data.page.title = translation;
				applied++;
				return;
			}
			const row = byKey[ key ];
			const st = rowState[ key ];
			if ( ! row || st.s !== '' ) {
				return;
			}
			if ( st.simple ) {
				st.plain = translation;
				st.t = rebuildSimple( row, translation );
			} else {
				st.t = translation;
			}
			st.p = 'paste';
			st.ok = true;
			applied++;
		} );
		const extra = Object.keys( found ).map( Number ).filter( ( n ) => n < 1 || n > copyList.length );
		dirty = true;
		render();
		let message = sprintf( _n( '%d traduzione inserita: rivedila e salva.', '%d traduzioni inserite: rivedile e salva.', applied, 'ept' ), applied );
		if ( missing.length ) {
			message += ' ' + sprintf( __( 'Numeri mancanti nel testo incollato: %s.', 'ept' ), missing.join( ', ' ) );
		}
		if ( extra.length ) {
			message += ' ' + sprintf( __( 'Numeri sconosciuti ignorati: %s.', 'ept' ), extra.join( ', ' ) );
		}
		notice( missing.length || extra.length ? 'warning' : 'success', message );
	}

	function pendingProposals() {
		return data.strings.filter( ( row ) => ! row.dynamic && rowState[ row.key ].p && rowState[ row.key ].s === '' ).length;
	}

	function acceptAll() {
		data.strings.forEach( ( row ) => {
			rowState[ row.key ].p = '';
		} );
		dirty = true;
		render();
	}

	/**
	 * Ask for proposals (memory, then DeepL) for the given rows, or for every row without a translation.
	 * Unsaved edits are saved first; proposals come back saved as unconfirmed drafts.
	 */
	function propose( keys, skipMemory ) {
		if ( busy ) {
			return Promise.resolve();
		}
		const before = dirty ? wp.apiFetch( { path: path(), method: 'POST', data: body( false ) } ) : Promise.resolve();
		setBusy( true );
		notice( 'info', __( 'Richiesta delle proposte in corso…', 'ept' ) );
		return before
			.then( () => wp.apiFetch( { path: path( '/propose' ), method: 'POST', data: { keys, skip_memory: !! skipMemory, include_title: ! data.page.title } } ) )
			.then( ( response ) => {
				setBusy( false );
				setData( response.screen );
				render();
				if ( response.error ) {
					notice( response.count ? 'warning' : 'info', ( response.count ? sprintf( _n( '%d proposta ricevuta.', '%d proposte ricevute.', response.count, 'ept' ), response.count ) + ' ' : '' ) + response.error );
				} else if ( response.count ) {
					notice( 'success', sprintf( _n( '%d proposta da rivedere: è evidenziata in viola finché non la confermi o la modifichi.', '%d proposte da rivedere: sono evidenziate in viola finché non le confermi o le modifichi.', response.count, 'ept' ), response.count ) + usageText( response.usage ) );
				} else {
					notice( 'info', __( 'Nessun testo da proporre.', 'ept' ) );
				}
			} )
			.catch( ( error ) => {
				setBusy( false );
				notice( 'error', error.message || __( 'Proposte non disponibili.', 'ept' ) );
			} );
	}

	function usageText( usage ) {
		if ( ! usage ) {
			return '';
		}
		if ( usage.warning ) {
			return ' ' + usage.warning;
		}
		return usage.limit ? ' ' + sprintf( __( 'DeepL: %1$s su %2$s caratteri usati nel periodo.', 'ept' ), usage.count.toLocaleString(), usage.limit.toLocaleString() ) : '';
	}

	function setAllSame( checked ) {
		const rows = data.strings.filter( ( row ) => ! row.dynamic && rowState[ row.key ].s !== 'excluded' );
		if ( checked ) {
			const translated = rows.filter( ( row ) => rowState[ row.key ].s === '' && rowState[ row.key ].t.trim() !== '' );
			let includeTranslated = true;
			if ( translated.length ) {
				includeTranslated = window.confirm(
					sprintf( _n( '%d testo è già tradotto. OK: rendi uguale all\'originale anche quello. Annulla: solo i testi senza traduzione.', '%d testi sono già tradotti. OK: rendi uguali all\'originale anche quelli. Annulla: solo i testi senza traduzione.', translated.length, 'ept' ), translated.length )
				);
			}
			bulkBackup = {};
			rows.forEach( ( row ) => {
				const st = rowState[ row.key ];
				if ( ! includeTranslated && st.t.trim() !== '' ) {
					return;
				}
				bulkBackup[ row.key ] = { s: st.s, ok: st.ok };
				st.s = 'same';
				st.ok = true;
			} );
		} else if ( bulkBackup ) {
			Object.entries( bulkBackup ).forEach( ( [ key, previous ] ) => {
				rowState[ key ].s = previous.s;
				rowState[ key ].ok = previous.ok;
			} );
			bulkBackup = null;
		}
		allSame = checked;
		dirty = true;
		render();
	}

	/* ------------------------------------------------------------------ */
	/* Collapsible panels (native <details>: keyboard and screen readers)   */
	/* ------------------------------------------------------------------ */

	const PANELS_KEY = 'ept-screen-panels';
	let panels = null; // id => open (remembered in this browser only)

	function panelState() {
		if ( panels === null ) {
			try {
				panels = JSON.parse( window.localStorage.getItem( PANELS_KEY ) || '{}' ) || {};
			} catch ( e ) {
				panels = {};
			}
		}
		return panels;
	}

	function panel( id, heading, title, count, defaultOpen, children ) {
		const state = panelState();
		const open = Object.prototype.hasOwnProperty.call( state, id ) ? !! state[ id ] : defaultOpen;
		const node = el( 'details', { className: 'ept-panel ept-panel--' + heading, open, 'data-panel': id }, [
			el( 'summary', {}, [
				el( heading, { className: 'ept-panel-title', text: title } ),
				count ? el( 'span', { className: 'ept-panel-count', id: 'ept-count-' + id, text: count } ) : null,
			] ),
		].concat( children ) );
		node.addEventListener( 'toggle', () => {
			panelState()[ id ] = node.open;
			try {
				window.localStorage.setItem( PANELS_KEY, JSON.stringify( panelState() ) );
			} catch ( e ) {
				// Storage unavailable (private window): the state just is not remembered.
			}
		} );
		return node;
	}

	function groupCount( group ) {
		const rows = data.strings.filter( ( row ) => row.group === group && ! row.dynamic );
		return sprintf( __( '%1$d di %2$d', 'ept' ), rows.filter( isDone ).length, rows.length );
	}

	function stringsCount() {
		const rows = data.strings.filter( ( row ) => ! row.dynamic );
		return sprintf( __( '%1$d di %2$d tradotti', 'ept' ), rows.filter( isDone ).length, rows.length );
	}

	function renderStrings() {
		if ( ! data.strings.length ) {
			return panel( 'strings', 'h2', __( 'Testi', 'ept' ), '', true, [ el( 'p', { text: __( 'Nessun testo da tradurre in questa pagina.', 'ept' ) } ) ] );
		}
		const groups = [];
		let current = null;
		data.strings.forEach( ( row ) => {
			if ( ! current || current.id !== row.group ) {
				current = { id: row.group, rows: [] };
				groups.push( current );
			}
			current.rows.push( row );
		} );
		const children = groups.map( ( group ) => {
			const list = el( 'div', { className: 'ept-rows' } );
			group.rows.forEach( ( row ) => list.appendChild( renderRow( row ) ) );
			const todo = group.rows.some( ( row ) => ! row.dynamic && ( ! isDone( row ) || row.state === 'modified' || rowState[ row.key ].p ) );
			return panel( 'group-' + group.id, 'h3', sprintf( __( 'Sezione %d', 'ept' ), group.id ), groupCount( group.id ), todo, [ list ] );
		} );
		return panel( 'strings', 'h2', __( 'Testi', 'ept' ), stringsCount(), true, children );
	}

	function rebuildSimple( row, plain ) {
		const escaped = String( plain ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /\r\n|\r|\n/g, '<br>' );
		return row.simple.prefix + escaped + row.simple.suffix;
	}

	function shownOriginal( row, st ) {
		return st.simple ? row.simple.original : row.original;
	}

	function renderRow( row ) {
		const st = rowState[ row.key ];
		const id = 'ept-t-' + row.key.replace( /[^a-zA-Z0-9_-]/g, '_' );
		const rowEl = el( 'div', { className: 'ept-row ept-row--' + row.state + ( st.p && st.s === '' ? ' ept-row--proposal' : '' ), 'data-key': row.key } );

		const meta = el( 'div', { className: 'ept-row-meta' }, [
			el( 'strong', { text: row.widget_label } ),
			el( 'code', { text: row.field } ),
			row.source === 'fallback' ? el( 'span', { className: 'ept-tag', text: __( 'rilevato automaticamente', 'ept' ) } ) : null,
			row.state === 'new' && ! row.dynamic ? el( 'span', { className: 'ept-tag ept-tag--new', text: __( 'nuovo', 'ept' ) } ) : null,
			row.state === 'modified' ? el( 'span', { className: 'ept-tag ept-tag--warn', text: __( 'originale modificato', 'ept' ) } ) : null,
			st.p && st.s === '' ? el( 'span', { className: 'ept-tag ept-tag--proposal', text: proposalLabel( st.p ) } ) : null,
		] );
		rowEl.appendChild( meta );

		const htmlView = ! st.simple && row.format !== 'text';
		const original = el( 'div', { className: 'ept-original' }, [
			el( 'div', { className: 'ept-original-text' + ( htmlView ? ' ept-code' : '' ), text: shownOriginal( row, st ) || '—' } ),
		] );
		if ( row.state === 'modified' && row.old_original ) {
			original.appendChild( el( 'details', {}, [ el( 'summary', { text: __( 'Originale precedente', 'ept' ) } ), el( 'div', { className: 'ept-code', text: row.old_original } ) ] ) );
		}
		rowEl.appendChild( original );

		const side = el( 'div', { className: 'ept-translation' } );
		if ( row.dynamic ) {
			side.appendChild( el( 'em', { text: __( 'Contenuto dinamico: viene copiato invariato.', 'ept' ) } ) );
			rowEl.appendChild( side );
			return rowEl;
		}

		const shown = shownOriginal( row, st );
		const long = htmlView || shown.length > LONG_TEXT || shown.includes( '\n' );
		const input = el( long ? 'textarea' : 'input', {
			id,
			className: 'large-text',
			rows: long ? Math.min( 8, Math.max( 2, Math.ceil( shown.length / 90 ), shown.split( '\n' ).length ) ) : null,
			type: long ? null : 'text',
			'aria-label': sprintf( __( 'Traduzione di: %s', 'ept' ), shown.slice( 0, 80 ) ),
			disabled: st.s === 'same' || st.s === 'excluded',
			onInput: ( event ) => {
				const value = event.target.value;
				if ( st.simple ) {
					st.plain = value;
					st.t = value.trim() === '' ? '' : rebuildSimple( row, value );
				} else {
					st.t = value;
				}
				st.ok = true;
				if ( st.p ) {
					st.p = '';
					rowEl.classList.remove( 'ept-row--proposal' );
					const tag = rowEl.querySelector( '.ept-tag--proposal' );
					if ( tag ) {
						tag.remove();
					}
				}
				setDirty( true );
				checkTags( row, rowEl );
				updateProgress();
			},
		} );
		input.value = st.s === 'same' ? shown : ( st.simple ? st.plain : st.t );
		side.appendChild( input );

		const warning = el( 'p', { className: 'ept-warning ept-tags-warning', hidden: true, text: __( 'Attenzione: il numero di tag HTML è diverso dall\'originale.', 'ept' ) } );
		side.appendChild( warning );

		const controls = el( 'div', { className: 'ept-row-controls' } );
		controls.appendChild(
			el( 'label', {}, [
				el( 'input', {
					type: 'checkbox',
					checked: st.s === 'same',
					onChange: ( event ) => {
						st.s = event.target.checked ? 'same' : '';
						st.ok = true;
						input.disabled = event.target.checked;
						input.value = event.target.checked ? shown : ( st.simple ? st.plain : st.t );
						setDirty( true );
						updateProgress();
					},
				} ),
				' ' + __( 'Uguale all\'originale', 'ept' ),
			] )
		);
		if ( row.source === 'fallback' ) {
			controls.appendChild(
				el( 'label', {}, [
					el( 'input', {
						type: 'checkbox',
						checked: st.s === 'excluded',
						onChange: ( event ) => {
							st.s = event.target.checked ? 'excluded' : '';
							st.ok = true;
							input.disabled = event.target.checked;
							setDirty( true );
							updateProgress();
						},
					} ),
					' ' + __( 'Escludi', 'ept' ),
				] )
			);
		}
		controls.appendChild(
			el( 'button', {
				type: 'button',
				className: 'button button-small',
				text: __( 'Copia originale', 'ept' ),
				onClick: () => {
					st.t = row.original;
					if ( st.simple ) {
						st.plain = row.simple.original;
					}
					input.value = shown;
					st.ok = true;
					setDirty( true );
					checkTags( row, rowEl );
					updateProgress();
				},
			} )
		);
		if ( st.p && st.s === '' ) {
			controls.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button button-small',
					text: __( 'Conferma proposta', 'ept' ),
					onClick: () => {
						st.p = '';
						setDirty( true );
						rowEl.replaceWith( renderRow( row ) );
					},
				} )
			);
		}
		if ( data.deepl.configured && row.format !== 'code' ) {
			controls.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button button-small',
					text: __( 'Ripeti proposta', 'ept' ),
					onClick: () => propose( [ row.key ], true ),
				} )
			);
		}
		if ( st.simple ) {
			controls.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button-link',
					text: __( 'Mostra HTML', 'ept' ),
					onClick: () => {
						st.simple = false;
						rowEl.replaceWith( renderRow( row ) );
					},
				} )
			);
		}
		if ( row.state === 'modified' ) {
			controls.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button button-small',
					text: __( 'Conferma traduzione', 'ept' ),
					onClick: ( event ) => {
						st.ok = true;
						event.target.disabled = true;
						setDirty( true );
					},
				} )
			);
		}
		side.appendChild( controls );
		rowEl.appendChild( side );
		setTimeout( () => checkTags( row, rowEl ), 0 );
		return rowEl;
	}

	function checkTags( row, rowEl ) {
		const st = rowState[ row.key ];
		const warning = rowEl.querySelector( '.ept-tags-warning' );
		if ( ! warning ) {
			return;
		}
		warning.hidden = st.simple || st.s !== '' || st.t.trim() === '' || tagCounts( row.original ) === tagCounts( st.t );
	}

	function applyFilter() {
		data.strings.forEach( ( row ) => {
			const node = app.querySelector( '.ept-row[data-key="' + CSS.escape( row.key ) + '"]' );
			if ( node ) {
				node.hidden = onlyTodo && ( isDone( row ) && row.state !== 'modified' );
			}
		} );
	}

	function updateProgress() {
		const rows = data.strings.filter( ( row ) => ! row.dynamic );
		const done = rows.filter( isDone ).length;
		const label = document.getElementById( 'ept-progress' );
		const bar = document.getElementById( 'ept-progress-bar' );
		if ( label ) {
			label.textContent = sprintf( __( '%1$d di %2$d testi', 'ept' ), done, rows.length );
		}
		if ( bar ) {
			bar.max = Math.max( 1, rows.length );
			bar.value = done;
		}
		const total = document.getElementById( 'ept-count-strings' );
		if ( total ) {
			total.textContent = stringsCount();
		}
		app.querySelectorAll( '[data-panel^="group-"]' ).forEach( ( node ) => {
			const counter = node.querySelector( '.ept-panel-count' );
			if ( counter ) {
				counter.textContent = groupCount( parseInt( node.getAttribute( 'data-panel' ).slice( 6 ), 10 ) );
			}
		} );
	}

	const LINK_TYPES = {
		page: __( 'Pagina tradotta', 'ept' ),
		home: __( 'Home della lingua', 'ept' ),
		page_untranslated: __( 'Pagina non ancora tradotta', 'ept' ),
		page_unpublished: __( 'Traduzione non pubblicata', 'ept' ),
		anchor: __( 'Ancora nella pagina', 'ept' ),
		file: __( 'File', 'ept' ),
		external: __( 'Esterno', 'ept' ),
		mailto: __( 'Email', 'ept' ),
		tel: __( 'Telefono', 'ept' ),
		post: __( 'Articolo', 'ept' ),
		internal_unknown: __( 'Interno', 'ept' ),
		empty: '—',
	};

	function renderLinks() {
		const wrap = el( 'div', { className: 'ept-links' } );
		const links = data.links || [];
		const pending = links.filter( ( link ) => link.untranslated || link.changed ).length;
		let count = sprintf( _n( '%d link', '%d link', links.length, 'ept' ), links.length );
		if ( pending ) {
			count += ' · ' + sprintf( _n( '%d da controllare', '%d da controllare', pending, 'ept' ), pending );
		}
		const node = panel( 'links', 'h2', __( 'Link', 'ept' ), links.length ? count : '', pending > 0, [ wrap ] );
		if ( ! links.length ) {
			wrap.appendChild( el( 'p', { text: __( 'Nessun link in questa pagina.', 'ept' ) } ) );
			return node;
		}
		wrap.appendChild( el( 'p', { className: 'description', text: sprintf( __( 'I link alle pagine del sito si convertono da soli nella versione %s quando quella pagina è tradotta e pubblicata (es. /%s/…). Quelli verso pagine non ancora tradotte restano in italiano finché non le traduci: allora il plugin propone di aggiornarli. Togli la spunta "Traduci" per lasciare un link in italiano; un URL manuale ha la precedenza su tutto. Ancore, file, email e siti esterni restano uguali, salvo URL manuale.', 'ept' ), data.lang.name, data.lang.prefix ) } ) );
		const table = el( 'table', { className: 'widefat striped ept-links-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { scope: 'col', text: __( 'Contesto', 'ept' ) } ),
			el( 'th', { scope: 'col', text: __( 'URL originale', 'ept' ) } ),
			el( 'th', { scope: 'col', text: __( 'URL proposto', 'ept' ) } ),
			el( 'th', { scope: 'col', text: __( 'Traduci', 'ept' ) } ),
			el( 'th', { scope: 'col', text: __( 'URL manuale', 'ept' ) } ),
		] ) ] ) );
		const body = el( 'tbody' );
		links.forEach( ( link ) => body.appendChild( renderLinkRow( link ) ) );
		table.appendChild( body );
		wrap.appendChild( table );
		return node;
	}

	function renderLinkRow( link ) {
		const st = linkState[ link.key ];
		const manual = el( 'input', {
			type: 'url',
			className: 'regular-text',
			value: st.mode === 'manual' ? st.url : '',
			placeholder: link.type === 'file' ? __( 'Versione nella lingua (facoltativo)', 'ept' ) : '',
			'aria-label': sprintf( __( 'URL manuale per %s', 'ept' ), link.original ),
			onInput: ( event ) => {
				const value = event.target.value.trim();
				st.url = value;
				st.mode = value ? 'manual' : ( checkbox && checkbox.checked ? 'translate' : 'keep' );
				setDirty( true );
			},
		} );
		const checkbox = link.choosable
			? el( 'input', {
				type: 'checkbox',
				checked: st.mode !== 'keep',
				'aria-label': sprintf( __( 'Traduci il link %s', 'ept' ), link.original ),
				onChange: ( event ) => {
					if ( st.mode !== 'manual' ) {
						st.mode = event.target.checked ? 'translate' : 'keep';
					}
					setDirty( true );
				},
			} )
			: null;
		const info = el( 'div', {}, [ el( 'span', { className: 'ept-tag', text: LINK_TYPES[ link.type ] || link.type } ) ] );
		if ( link.message ) {
			info.appendChild( el( 'p', { className: 'description', text: link.message } ) );
		}
		if ( link.untranslated ) {
			info.appendChild( el( 'a', { href: config.screenUrl + '&post=' + link.untranslated + '&lang=' + encodeURIComponent( config.lang ), text: __( 'Traduci quella pagina', 'ept' ) } ) );
		}
		if ( link.changed ) {
			info.appendChild( el( 'p', { className: 'ept-warning', text: __( 'Il link italiano è cambiato: la scelta precedente non vale più.', 'ept' ) } ) );
		}
		return el( 'tr', { 'data-link': link.key }, [
			el( 'td', {}, [ el( 'strong', { text: link.widget } ), link.context ? el( 'div', { className: 'description', text: link.context } ) : null ] ),
			el( 'td', {}, [ el( 'code', { className: 'ept-url', text: link.original } ) ] ),
			el( 'td', {}, [ link.translatable ? el( 'code', { className: 'ept-url', text: link.proposed } ) : el( 'span', { text: __( 'resta uguale', 'ept' ) } ), info ] ),
			el( 'td', {}, [ checkbox || el( 'span', { title: __( 'Questo tipo di link non si traduce: se serve, usa l\'URL manuale.', 'ept' ), 'aria-label': __( 'Non traducibile', 'ept' ), text: '—' } ) ] ),
			el( 'td', {}, [ manual ] ),
		] );
	}

	function renderRelink() {
		const pages = data.relink || [];
		if ( ! pages.length ) {
			return null;
		}
		return el( 'div', { className: 'notice notice-warning inline ept-relink' }, [
			el( 'p', { text: sprintf( _n( '%1$d pagina in %2$s punta ancora alla versione italiana di questa pagina:', '%1$d pagine in %2$s puntano ancora alla versione italiana di questa pagina:', pages.length, 'ept' ), pages.length, data.lang.name ) + ' ' + pages.map( ( page ) => page.title ).join( ', ' ) } ),
			el( 'p', {}, [ el( 'button', {
				type: 'button',
				className: 'button',
				id: 'ept-relink',
				text: __( 'Aggiorna i link in queste pagine', 'ept' ),
				onClick: () => {
					setBusy( true );
					wp.apiFetch( { path: path( '/relink' ), method: 'POST', data: {} } )
						.then( ( response ) => {
							setBusy( false );
							setData( response.screen );
							render();
							notice( 'success', sprintf( _n( 'Link aggiornati in %d pagina.', 'Link aggiornati in %d pagine.', response.updated.length, 'ept' ), response.updated.length ) );
						} )
						.catch( ( error ) => {
							setBusy( false );
							notice( 'error', error.message );
						} );
				},
			} ) ] ),
		] );
	}

	function renderFooter() {
		return el( 'div', { className: 'ept-footer' }, [
			el( 'button', { type: 'button', className: 'button button-secondary', id: 'ept-save', text: __( 'Salva bozza traduzione', 'ept' ), onClick: () => save( false ) } ),
			el( 'button', {
				type: 'button',
				className: 'button button-primary',
				id: 'ept-generate',
				text: data.target ? __( 'Aggiorna pagina', 'ept' ) : __( 'Crea pagina', 'ept' ),
				onClick: () => save( true ),
			} ),
			el( 'span', { className: 'spinner', id: 'ept-spinner' } ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Save / generate                                                      */
	/* ------------------------------------------------------------------ */

	function body( confirm ) {
		const strings = {};
		data.strings.forEach( ( row ) => {
			if ( ! row.dynamic ) {
				const st = rowState[ row.key ];
				strings[ row.key ] = { t: st.s ? '' : st.t, s: st.s, ok: st.ok, p: st.s ? '' : st.p };
			}
		} );
		const links = {};
		( data.links || [] ).forEach( ( link ) => {
			links[ link.key ] = linkState[ link.key ];
		} );
		return { page: data.page, strings, links, confirm: !! confirm };
	}

	function setBusy( value ) {
		busy = value;
		[ 'ept-save', 'ept-generate', 'ept-propose', 'ept-accept-all', 'ept-copy', 'ept-paste' ].forEach( ( id ) => {
			const button = document.getElementById( id );
			if ( button ) {
				button.disabled = value;
			}
		} );
		const spinner = document.getElementById( 'ept-spinner' );
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', value );
		}
	}

	function save( generate, confirm, overwrite ) {
		if ( busy ) {
			return;
		}
		if ( generate && ! data.parent.ok ) {
			notice( 'error', data.parent.message );
			return;
		}
		const pending = generate && ! confirm && ! overwrite ? pendingProposals() : 0;
		if ( pending && ! window.confirm( sprintf( _n( 'C\'è %d proposta automatica non confermata: verrà usata così com\'è. Continuare?', 'Ci sono %d proposte automatiche non confermate: verranno usate così come sono. Continuare?', pending, 'ept' ), pending ) ) ) {
			return;
		}
		setBusy( true );
		wp.apiFetch( { path: path( generate ? '/generate' : '' ), method: 'POST', data: Object.assign( body( confirm ), { overwrite: !! overwrite } ) } )
			.then( ( response ) => {
				setBusy( false );
				setData( response.screen );
				render();
				const sanitized = response.sanitized || [];
				if ( generate ) {
					const t = response.target;
					const message = response.created ? __( 'Pagina tradotta creata.', 'ept' ) : __( 'Pagina tradotta aggiornata.', 'ept' );
					notice( 'success', message + ( response.missing ? ' ' + sprintf( _n( '%d testo è rimasto in italiano.', '%d testi sono rimasti in italiano.', response.missing, 'ept' ), response.missing ) : '' ), [
						{ href: t.view_link, text: __( 'Visualizza', 'ept' ), blank: true },
						{ href: t.elementor_link, text: __( 'Apri in Elementor', 'ept' ) },
					] );
				} else {
					notice( 'success', __( 'Bozza della traduzione salvata. La pagina tradotta non è stata modificata.', 'ept' ) );
				}
				if ( sanitized.length ) {
					notice( 'warning', sprintf( _n( 'In %d testo parte del markup HTML è stata rimossa perché non hai il permesso di inserire HTML libero.', 'In %d testi parte del markup HTML è stata rimossa perché non hai il permesso di inserire HTML libero.', sanitized.length, 'ept' ), sanitized.length ) );
				}
			} )
			.catch( ( error ) => {
				setBusy( false );
				if ( error.code === 'ept_incomplete' ) {
					if ( window.confirm( error.message + '\n\n' + __( 'Creare o aggiornare la pagina comunque?', 'ept' ) ) ) {
						save( true, true, overwrite );
					}
					return;
				}
				if ( error.code === 'ept_manual_changes' ) {
					if ( window.confirm( error.message + '\n\n' + __( 'Aggiornare comunque e sovrascrivere le modifiche manuali?', 'ept' ) ) ) {
						save( true, confirm, true );
					}
					return;
				}
				notice( 'error', error.message || __( 'Errore durante il salvataggio.', 'ept' ) );
			} );
	}

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	load();
}() );
