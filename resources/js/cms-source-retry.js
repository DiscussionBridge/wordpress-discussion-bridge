(function () {
  'use strict';

  // Submit independently of WordPress's surrounding post-edit form. No source
  // request, credential or automatic retry is carried by this control.
  document.addEventListener('click', function (event) {
    const button = event.target instanceof Element && event.target.closest('[data-discussionbridge-retry]');
    if (!button || button.disabled) return;
    const form = document.createElement('form');
    form.method = 'post';
    form.action = button.dataset.actionUrl;
    const fields = {
      action: 'discussionbridge_retry',
      post_id: button.dataset.postId,
      request_identity: button.dataset.requestIdentity,
      _wpnonce: button.dataset.nonce
    };
    Object.entries(fields).forEach(function ([name, value]) {
      const field = document.createElement('input');
      field.type = 'hidden'; field.name = name; field.value = value;
      form.appendChild(field);
    });
    button.disabled = true;
    document.body.appendChild(form);
    form.submit();
  });
})();
