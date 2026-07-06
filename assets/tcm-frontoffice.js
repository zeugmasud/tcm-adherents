document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tcm-tabs').forEach(function (tabs) {
    const buttons = tabs.querySelectorAll('.tcm-tab');
    const panels = tabs.querySelectorAll('.tcm-tabpanel');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        const target = button.getAttribute('data-tab');
        buttons.forEach(function (btn) { btn.classList.remove('is-active'); });
        button.classList.add('is-active');
        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.id === target);
        });
      });
    });
  });
});
