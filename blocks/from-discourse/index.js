(function (blocks, blockEditor, components, element, i18n) {
  "use strict";

  blocks.registerBlockType("discussionbridge/from-discourse", {
    edit: function (props) {
      var blockProps = blockEditor.useBlockProps();
      var supportedModes = ["none", "simple", "full", "interactive"];
      var mode = Object.prototype.hasOwnProperty.call(props.attributes, "commentsMode")
        ? props.attributes.commentsMode
        : "interactive";
      var validMode = supportedModes.indexOf(mode) !== -1;
      var modeOptions = [
        { label: i18n.__("No discussion", "discussionbridge"), value: "none" },
        { label: i18n.__("Simple comments", "discussionbridge"), value: "simple" },
        { label: i18n.__("Standard Discourse comments", "discussionbridge"), value: "full" },
        { label: i18n.__("DiscussionBridge Interactive", "discussionbridge"), value: "interactive" },
      ];
      if (!validMode) {
        modeOptions.unshift({
          label: i18n.__("Unsupported mode — no discussion", "discussionbridge"),
          value: "",
          disabled: true,
        });
      }
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
        element.createElement(components.SelectControl, {
          label: i18n.__("Discussion mode", "discussionbridge"),
          value: validMode ? mode : "",
          options: modeOptions,
          help: validMode ? undefined : i18n.__(
            "Choose a supported mode to enable discussion. The saved value has not been changed.",
            "discussionbridge",
          ),
          onChange: function (value) {
            if (supportedModes.indexOf(value) !== -1) {
              props.setAttributes({ commentsMode: value });
            }
          },
        }),
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n);
