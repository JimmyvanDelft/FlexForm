( function () {
	const rows = document.getElementById( 'ff-actions' );
	if ( !rows ) {
		return;
	}
	let index = rows.children.length;
	function update( row ) {
		const option = row.querySelector( 'select' ).value;
		row.querySelectorAll( '[data-option]' ).forEach( ( group ) => {
			group.hidden = group.dataset.option !== option;
			group.querySelectorAll( 'input,select' ).forEach( ( field ) => {
				field.disabled = group.hidden;
			} );
		} );
	}
	Array.from( rows.children ).forEach( update );
	document.getElementById( 'ff-add' ).onclick = () => {
		const row = document.getElementById( 'ff-action-template' ).content.firstElementChild.cloneNode( true );
		row.querySelectorAll( '[name]' ).forEach( ( field ) => {
			field.name = field.name.replace( '[INDEX]', '[' + index + ']' );
		} );
		index++;
		rows.appendChild( row );
		update( row );
	};
	rows.onchange = ( e ) => update( e.target.closest( 'fieldset' ) );
	rows.onclick = ( e ) => {
		const row = e.target.closest( 'fieldset' );
		if ( e.target.classList.contains( 'ff-remove' ) ) {
			row.remove();
		} else if ( e.target.classList.contains( 'ff-up' ) && row.previousElementSibling ) {
			rows.insertBefore( row, row.previousElementSibling );
		}
	};
}() );
