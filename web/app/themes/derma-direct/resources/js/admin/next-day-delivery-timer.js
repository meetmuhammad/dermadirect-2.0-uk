const setHolidayRowDefaults = (root = document) => {
  root.querySelectorAll('[data-name="end_hour"] input[type="number"]').forEach((input) => {
    if (input.value === '') {
      input.value = '23';
    }
  });

  root.querySelectorAll('[data-name="end_minute"] input[type="number"]').forEach((input) => {
    if (input.value === '') {
      input.value = '59';
    }
  });
};

document.addEventListener('DOMContentLoaded', () => {
  setHolidayRowDefaults(document);

  if (window.acf && typeof window.acf.addAction === 'function') {
    window.acf.addAction('append', ($element) => {
      const element = $element && $element.jquery ? $element[0] : $element;
      setHolidayRowDefaults(element || document);
    });
  }
});
