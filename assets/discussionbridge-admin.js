(function ($) {
  'use strict';

  const input = $('#discussionbridge_service_author');
  const config = window.DiscussionBridgeAdmin;
  if (!input.length || !config || !config.ajaxUrl || !config.nonce) {
    return;
  }

  input.autocomplete({
    minLength: 0,
    delay: 150,
    source(request, respond) {
      $.getJSON(config.ajaxUrl, {
        action: 'discussionbridge_search_authors',
        nonce: config.nonce,
        term: request.term
      }).done(respond).fail(function () { respond([]); });
    },
    select(_event, ui) {
      input.val(ui.item.value);
      return false;
    }
  }).on('focus', function () {
    if (!this.value) {
      input.autocomplete('search', '');
    }
  });
})(jQuery);
