(function (wp) {
  var registerBlockType = wp.blocks.registerBlockType;
  var el = wp.element.createElement;
  var useBlockProps = wp.blockEditor.useBlockProps;

  registerBlockType('wcai/ai-assistant', {
    edit: function () {
      var props = useBlockProps({ className: 'wcai-block-preview' });
      return el(
        'div',
        props,
        el('strong', null, 'AI Shopping Assistant'),
        el('p', null, 'Shoppers will see the embedded chat panel on the front end.')
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
