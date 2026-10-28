(function () {
  const config = window.DiscussionBridgeCatalog;
  if (!config) return;
  document.querySelectorAll('[data-discussionbridge-catalog]').forEach((button) => {
    button.addEventListener('click', async () => {
      const status = document.getElementById('discussionbridge-catalog-status');
      const buttons = document.querySelectorAll('[data-discussionbridge-catalog]');
      buttons.forEach((item) => { item.disabled = true; });
      try {
        let done = false;
        while (!done) {
          const body = new URLSearchParams({ action: 'discussionbridge_refresh_catalogs', nonce: config.nonce, kind: button.dataset.discussionbridgeCatalog });
          const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.data.message || 'The catalog refresh needs attention.');
          done = result.data.done;
          status.textContent = `${result.data.received} of ${result.data.total} entries uploaded${done ? '.' : '…'}`;
        }
      } catch (error) { status.textContent = error.message; }
      finally { buttons.forEach((item) => { item.disabled = false; }); }
    });
  });
})();
