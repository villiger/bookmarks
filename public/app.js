/* Behaviour that used to be inline handlers; everything is delegated, so cards swapped in by htmx just work. */
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };

  /* verify.php and the bookmarklet popup */
  var f = $('form[data-autosubmit]'); if (f) f.submit();
  if (document.body.hasAttribute('data-autoclose')) setTimeout(function () { window.close(); }, 1000);

  /* search box: the first sort option reads "Best match" while a query is typed, "Shuffle" otherwise */
  var q = $('[name=q]'), first = $('[name=sort] option');
  if (q && first) { var label = function () { first.textContent = q.value.trim() ? 'Best match' : 'Shuffle'; }; q.addEventListener('input', label); label(); }
  if (q && matchMedia('(hover:hover)').matches && !location.search) q.focus();

  document.addEventListener('click', function (e) {
    var t = e.target, el;
    if ((el = t.closest('[data-alert]'))) { alert(el.dataset.alert); e.preventDefault(); return; }
    if ((el = t.closest('[data-copy]'))) { navigator.clipboard.writeText(document.getElementById(el.dataset.copy).value); el.textContent = 'Copied'; return; }
    if ((el = t.closest('[data-reshuffle]'))) {   // new seed, back to the shuffle order
      var bar = el.parentNode, s = $('select', bar);
      $('[name=seed]', bar).value = Math.floor(Math.random() * 999999) + 1; s.value = ''; htmx.trigger(s, 'change'); return;
    }
    /* "+" on a card: first press reveals the tag field and focuses it; with a value it submits; empty again it closes. */
    if ((el = t.closest('.addbtn'))) {
      var form = el.form, input = $('input[name=tag]', form);
      if (!form.classList.contains('open')) { form.classList.add('open'); input.focus(); e.preventDefault(); }
      else if (input.value.trim() === '') { form.classList.remove('open'); input.blur(); e.preventDefault(); }
      return;
    }
    /* touch screens: tapping a card toggles its action buttons */
    var c = t.closest('.card');
    if (!c || !$('.acts', c) || t.closest('a,button,input,textarea,select,form')) return;
    if (!matchMedia('(hover:none),(max-width:600px)').matches) return;
    var open = c.classList.contains('open');
    document.querySelectorAll('.card.open').forEach(function (x) { x.classList.remove('open'); });
    if (!open) c.classList.add('open');
  });

  document.addEventListener('submit', function (e) {
    var m = e.target.dataset && e.target.dataset.confirm;
    if (m && !confirm(m)) e.preventDefault();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && e.target.matches && e.target.matches('.addtag input')) { e.target.value = ''; e.target.form.classList.remove('open'); }
  });
  document.addEventListener('focusout', function (e) {
    var f = e.target.closest && e.target.closest('.addtag');
    if (f && e.target.matches('input') && e.target.value.trim() === '') setTimeout(function () { if (!f.contains(document.activeElement)) f.classList.remove('open'); }, 150);
  });

  /* htmx hooks (replace what used to be hx-on, hx-vals="js:…" and a trigger filter, which all needed 'unsafe-eval') */
  document.addEventListener('bm-added', function (e) { if (e.target.reset) e.target.reset(); });   // add field: clear after a save
  document.addEventListener('htmx:configRequest', function (e) {                                  // search keeps the active tag filter
    if (e.detail.elt.matches('[name=q],[name=sort]')) e.detail.parameters.tag = new URLSearchParams(location.search).get('tag') || '';
  });
  document.addEventListener('htmx:beforeRequest', function (e) {                                  // a polling card never refreshes while open or being typed in (the swap would wipe it)
    var c = e.detail.elt;
    if (c.hasAttribute('data-poll') && (c.classList.contains('open') || c.querySelector('.addtag.open, input:focus'))) e.preventDefault();
  });
})();
