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

  registerBlockType('wcai/ai-assistant', {
    edit: function (props) {
      var attributes = props.attributes || {};
      var setAttributes = props.setAttributes;
      var type = attributes.type || 'panel';
      var label = attributes.label || '';
      var blockProps = useBlockProps({ className: 'wcai-block-preview' });

      var typeLabels = {
        search: __('AI search bar — great in hero or header', 'wc-ai-shopping-assistant'),
        button: __('Ask AI button — opens the assistant', 'wc-ai-shopping-assistant'),
        panel: __('Full chat panel — embed on any page', 'wc-ai-shopping-assistant'),
        floating: __('Floating bubble — page-local launcher', 'wc-ai-shopping-assistant'),
      };

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Widget layout', 'wc-ai-shopping-assistant'), initialOpen: true },
            el(SelectControl, {
              label: __('Type', 'wc-ai-shopping-assistant'),
              value: type,
              options: [
                { label: 'Search bar', value: 'search' },
                { label: 'Button', value: 'button' },
                { label: 'Chat panel', value: 'panel' },
                { label: 'Floating', value: 'floating' },
              ],
              onChange: function (v) {
                setAttributes({ type: v });
              },
            }),
            el(TextControl, {
              label: __('Custom label (button / search)', 'wc-ai-shopping-assistant'),
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
          el('strong', null, __('AI Shopping Assistant', 'wc-ai-shopping-assistant')),
          el('p', null, typeLabels[type] || typeLabels.panel),
          el('p', null, el('code', null, '[wc_ai_assistant type="' + type + '"]'))
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
