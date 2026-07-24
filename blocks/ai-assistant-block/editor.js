(function (wp) {
  var registerBlockType = wp.blocks.registerBlockType;
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var SelectControl = wp.components.SelectControl;
  var TextControl = wp.components.TextControl;
  var __ = wp.i18n.__;

  registerBlockType('shopask/ai-assistant', {
    edit: function (props) {
      var attributes = props.attributes || {};
      var setAttributes = props.setAttributes;
      var type = attributes.type || 'panel';
      var label = attributes.label || '';
      var blockProps = useBlockProps({ className: 'wcai-block-preview' });

      var typeLabels = {
        search: __('ShopAsk search bar — great in hero or header', 'shopask-ai-shopping-assistant'),
        button: __('Ask ShopAsk button — opens the assistant', 'shopask-ai-shopping-assistant'),
        panel: __('Full chat panel — embed on any page', 'shopask-ai-shopping-assistant'),
        floating: __('Floating bubble — page-local launcher', 'shopask-ai-shopping-assistant'),
      };

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Widget layout', 'shopask-ai-shopping-assistant'), initialOpen: true },
            el(SelectControl, {
              label: __('Type', 'shopask-ai-shopping-assistant'),
              value: type,
              options: [
                { label: __('Search bar', 'shopask-ai-shopping-assistant'), value: 'search' },
                { label: __('Button', 'shopask-ai-shopping-assistant'), value: 'button' },
                { label: __('Chat panel', 'shopask-ai-shopping-assistant'), value: 'panel' },
                { label: __('Floating', 'shopask-ai-shopping-assistant'), value: 'floating' },
              ],
              onChange: function (v) {
                setAttributes({ type: v });
              },
            }),
            el(TextControl, {
              label: __('Custom label (button / search)', 'shopask-ai-shopping-assistant'),
              value: label,
              onChange: function (v) {
                setAttributes({ label: v });
              },
            })
          )
        ),
        el(
          'div',
          blockProps,
          el('strong', null, __('ShopAsk AI', 'shopask-ai-shopping-assistant')),
          el('p', null, typeLabels[type] || typeLabels.panel),
          el('p', null, el('code', null, '[shopask_ai type="' + type + '"]'))
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
