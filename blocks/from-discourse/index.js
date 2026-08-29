(function (blocks, blockEditor, components, element, i18n) {
  "use strict";

  blocks.registerBlockType("discussionbridge/from-discourse", {
    edit: function (props) {
      var blockProps = blockEditor.useBlockProps();
      return element.createElement(
        "div",
        blockProps,
        element.createElement(components.TextControl, {
          label: i18n.__("Bridge Record resource ID", "discussionbridge"),
          value: props.attributes.resourceId || "",
          onChange: function (value) {
            props.setAttributes({ resourceId: value.trim() });
          },
          help: i18n.__(
            "The server retrieves and renders this From Discourse record without exposing the connection credential.",
            "discussionbridge",
          ),
        }),
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n);
