/**
 * Editor side of the feed-item-brief/attribution block. The block is
 * dynamic: readers see the server render from post meta. The saved markup
 * is a static fallback kept as it is. The values are read-only here;
 * corrections go through the Source review panel.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var RawHTML = wp.element.RawHTML;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var useSelect = wp.data.useSelect;

	function Edit(props) {
		var meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		}, []);
		var byline = [];
		try {
			byline = JSON.parse(meta._feed_item_brief_byline || '[]');
		} catch (e) {
			byline = [];
		}
		var rows = [
			[__('Source', 'feed-item-brief'), meta._feed_item_brief_source_name || ''],
			[__('Link', 'feed-item-brief'), meta._feed_item_brief_source_url || ''],
			[__('Byline', 'feed-item-brief'), byline.join(', ')],
			[__('Date reason', 'feed-item-brief'), meta._feed_item_brief_date_reason || '']
		];
		return el(
			'div',
			useBlockProps(),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Attribution (read-only)', 'feed-item-brief'), initialOpen: true },
					rows.map(function (row) {
						return el('p', { key: row[0] }, el('strong', null, row[0] + ': '), row[1]);
					}),
					el('p', null, __('Correct these values in the Source review panel.', 'feed-item-brief'))
				)
			),
			el(RawHTML, null, props.attributes.fallback || '')
		);
	}

	wp.blocks.registerBlockType('feed-item-brief/attribution', {
		apiVersion: 3,
		title: __('Source attribution', 'feed-item-brief'),
		category: 'text',
		icon: 'admin-links',
		supports: { html: false, inserter: false, multiple: false, reusable: false },
		attributes: {
			lock: { type: 'object' },
			fallback: { type: 'string', source: 'html', selector: 'div.feed-item-brief-attribution' }
		},
		edit: Edit,
		save: function (props) {
			return el(
				'div',
				{ className: 'wp-block-feed-item-brief-attribution feed-item-brief-attribution' },
				el(RawHTML, null, props.attributes.fallback || '')
			);
		}
	});
}(window.wp));
