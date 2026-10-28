/* Native editor token captured before editing; unrelated posts and routes are untouched. */
(function () {
  const state = window.DiscussionBridgeCmsEditor;
  if (!state || !window.wp || !wp.apiFetch || typeof state.revision !== 'string') return;
  let revision = state.revision;
  wp.apiFetch.use(async (options, next) => {
    const path = (options.path || '').split('?')[0];
    const method = (options.method || 'GET').toUpperCase();
    if (path !== state.path || !['POST', 'PUT', 'PATCH'].includes(method)) return next(options);
    const result = await next({ ...options, data: { ...options.data, discussionbridge_native_revision: revision } });
    if (result && typeof result.discussionbridge_native_revision === 'string') revision = result.discussionbridge_native_revision;
    return result;
  });
})();
