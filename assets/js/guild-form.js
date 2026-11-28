/* Prevent submitting an oversized selected image; server-side checks remain authoritative. */
(function () {
    'use strict';
    var field = document.getElementById('gob-photo');
    var warning = document.getElementById('gob-photo-warning');
    if (!field || !field.form || !warning) return;
    var max = Number(field.getAttribute('data-max-bytes')) || 0;
    function check() {
        var file = field.files && field.files[0];
        if (max > 0 && file && file.size > max) {
            warning.textContent = 'This photograph exceeds the server limit (' + (max / 1048576).toFixed(1) + ' MB). Choose a smaller photograph before saving.';
            warning.hidden = false;
            return false;
        }
        warning.textContent = '';
        warning.hidden = true;
        return true;
    }
    field.addEventListener('change', check);
    field.form.addEventListener('submit', function (event) {
        if (!check()) { event.preventDefault(); field.focus(); }
    });
}());
