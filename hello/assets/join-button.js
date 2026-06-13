(() => {
  const buttons = document.querySelectorAll('.hello-join-btn[data-join-uri]');
  const copyButtons = document.querySelectorAll('.hello-copy-btn[data-copy-value]');

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      window.setTimeout(() => {
        if (document.visibilityState === 'visible') {
          const fallback = button.closest('.hello-join')?.querySelector('.hello-copy-btn');
          fallback?.focus();
        }
      }, 900);
    });
  });

  copyButtons.forEach((button) => {
    const label = button.getAttribute('data-copy-label') || button.textContent || 'Copy room address';
    const copiedLabel = button.getAttribute('data-copied-label') || 'Copied';

    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-copy-value') || '';
      const status = button.closest('.hello-join')?.querySelector('.hello-copy-status');

      if (!value) {
        return;
      }

      try {
        await copyText(value);
        button.textContent = copiedLabel;
        if (status) {
          status.textContent = copiedLabel;
        }
        window.setTimeout(() => {
          button.textContent = label;
          if (status) {
            status.textContent = '';
          }
        }, 1800);
      } catch {
        if (status) {
          status.textContent = value;
        }
      }
    });
  });

  async function copyText(value) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }

    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    input.remove();
  }
})();
