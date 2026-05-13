// Stub for @wordpress/components — not installed as a standalone npm package.
// Provides minimal implementations of the components used by source files
// tested in this project. Individual tests may override these via jest.mock().
const React = require( 'react' );

module.exports = {
	Button: ( { children, onClick, disabled, type, variant, isBusy, style } ) =>
		React.createElement( 'button', { onClick, disabled, type, style }, children ),
	SelectControl: ( { label, value, onChange, options = [], disabled } ) =>
		React.createElement(
			'select',
			{ 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ), disabled },
			options.map( ( o ) =>
				React.createElement( 'option', { key: o.value, value: o.value }, o.label )
			)
		),
	TextControl: ( { label, value, onChange, type, placeholder, disabled } ) =>
		React.createElement( 'input', {
			'aria-label': label,
			value,
			onChange: ( e ) => onChange( e.target.value ),
			type: type ?? 'text',
			placeholder,
			disabled,
		} ),
	TabPanel: ( { children, tabs, className } ) =>
		React.createElement(
			'div',
			{ className },
			tabs.map( ( tab ) =>
				React.createElement(
					'button',
					{ key: tab.name, role: 'tab' },
					tab.title
				)
			),
			children( tabs[ 0 ] )
		),
	Notice: ( { children, status, isDismissible, onRemove } ) =>
		React.createElement( 'div', { role: 'alert', 'data-status': status }, children ),
};
