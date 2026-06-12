(() => {
  const buttons = document.querySelectorAll('.beeper-join-btn[data-matrix-uri][data-web-uri]');

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const webUri = button.getAttribute('data-web-uri');

      window.setTimeout(() => {
        if (document.visibilityState === 'visible' && webUri) {
          const fallback = button.closest('.hello-join')?.querySelector('.hello-hint a');
          fallback?.focus();
        }
      }, 900);
    });
  });
})();
