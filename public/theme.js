try { if (localStorage.getItem('convert-theme') === 'dark') document.documentElement.dataset.theme = 'dark'; } catch { /* A stored theme is optional. */ }
