  document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('cf7-popup-overlay');
    var closeBtn = document.getElementById('cf7-popup-close');
    var brandButtons = document.querySelectorAll('.training-brand');

    // Nothing to do if the popup markup is not on this page
    if (!overlay) {
      return;
    }

    function closePopup() {
      overlay.classList.remove('active');
    }

    if (brandButtons.length) {
      brandButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          overlay.classList.add('active');

          // Get value from aria-label (fallback to button text if not set)
          var academyName = button.getAttribute('aria-label') || (button.textContent || '').trim();

          // Auto-fill the Training Academy field in the CF7 form
          var academyField = document.getElementById('training-academy');
          if (academyField) {
            academyField.value = academyName;
          }
        });
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', closePopup);
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        closePopup();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('active')) {
        closePopup();
      }
    });
  });
