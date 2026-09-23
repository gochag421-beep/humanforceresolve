// Candidate registration form.
// Kept separate from script.js so it works even when WebGL is unavailable.
//
// Delivery: the form POSTs to a form-relay service that forwards the submission
// to EMAIL. If the relay is unreachable or refuses the request, we fall back to
// opening the visitor's mail client with everything pre-filled, so a submission
// is never silently lost.
//
// ---------------------------------------------------------------------------
// SETUP (one-time, ~1 minute):
//   1. Submit the form once on the live site.
//   2. FormSubmit emails contact@humanforcesolutions.com an "Activate Form" link.
//   3. Click that link. From then on every submission lands in the inbox.
// Until step 3 the relay returns {"success":"false"} and the page honestly falls
// back to the mail-client flow instead of pretending it was delivered.
// ---------------------------------------------------------------------------

const EMAIL = 'contact@humanforcesolutions.com';
const FORM_ENDPOINT = `https://formsubmit.co/ajax/${EMAIL}`;

class DeliveryError extends Error {}

const form = document.getElementById('regForm');
if (form) setup(form);

function setup(form) {
  const status = document.getElementById('formStatus');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = submitBtn.querySelector('.btn-text');
  const FIELDS = ['f-name', 'f-phone', 'f-email', 'f-role', 'f-city', 'f-exp', 'f-msg', 'f-consent'];

  function setError(id, message) {
    const input = document.getElementById(id);
    const box = form.querySelector(`.err[data-for="${id}"]`);
    if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (!box) return;
    box.textContent = message || '';
    box.hidden = !message;
  }

  function clearErrors() {
    FIELDS.forEach((id) => setError(id, ''));
    status.hidden = true;
  }

  /* --------------------------- validation --------------------------- */

  function validate() {
    clearErrors();
    const errors = [];
    const val = (id) => form.querySelector(`#${id}`).value.trim();

    if (val('f-name').length < 3) errors.push(['f-name', 'Γράψε το ονοματεπώνυμό σου.']);

    const phoneDigits = val('f-phone').replace(/[^\d+]/g, '');
    if (phoneDigits.replace(/\D/g, '').length < 8) {
      errors.push(['f-phone', 'Γράψε ένα τηλέφωνο επικοινωνίας.']);
    }

    if (!/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(val('f-email'))) {
      errors.push(['f-email', 'Γράψε ένα σωστό email, π.χ. name@example.com.']);
    }

    if (!val('f-role')) errors.push(['f-role', 'Διάλεξε τη θέση που σε ενδιαφέρει.']);

    if (!form.querySelector('#f-consent').checked) {
      errors.push(['f-consent', 'Χρειάζεται η συγκατάθεσή σου για να προχωρήσουμε.']);
    }

    errors.forEach(([id, msg]) => setError(id, msg));

    if (errors.length) {
      const first = form.querySelector(`#${errors[0][0]}`);
      first.focus();
      showStatus('error', 'Έλεγξε τα πεδία που σημειώθηκαν παραπάνω.');
      return null;
    }

    return {
      name: val('f-name'),
      phone: val('f-phone'),
      email: val('f-email'),
      role: val('f-role'),
      city: val('f-city') || '—',
      experience: val('f-exp') || '—',
      message: val('f-msg') || '—',
    };
  }

  /* ------------------------------ status ---------------------------- */

  function showStatus(kind, html) {
    status.className = `form-status full ${kind}`;
    status.innerHTML = html;
    status.hidden = false;
  }

  function setBusy(busy) {
    submitBtn.disabled = busy;
    submitBtn.classList.toggle('busy', busy);
    btnText.textContent = busy ? 'Αποστολή…' : 'Αποστολή καταχώρισης';
  }

  /* ------------------------------ mailto ---------------------------- */

  function mailtoFallback(data) {
    const body = [
      `Ονοματεπώνυμο: ${data.name}`,
      `Τηλέφωνο: ${data.phone}`,
      `Email: ${data.email}`,
      `Θέση: ${data.role}`,
      `Περιοχή: ${data.city}`,
      `Εμπειρία: ${data.experience}`,
      '',
      'Μήνυμα:',
      data.message,
    ].join('\n');
    const url = `mailto:${EMAIL}?subject=${encodeURIComponent(`Καταχώριση υποψηφίου — ${data.name}`)}`
      + `&body=${encodeURIComponent(body)}`;
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    a.click();
  }

  /* ------------------------------ submit ---------------------------- */

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = validate();
    if (!data) return;

    // honeypot: a real person never fills the hidden field
    if (form.querySelector('#f-company').value.trim()) {
      showStatus('ok', 'Ευχαριστούμε! Η καταχώρισή σου καταγράφηκε.');
      form.reset();
      return;
    }

    setBusy(true);
    showStatus('info', 'Στέλνουμε την καταχώρισή σου στο email της ομάδας…');

    try {
      const res = await fetch(FORM_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          _subject: `Καταχώριση υποψηφίου — ${data.name}`,
          _template: 'table',
          _captcha: 'false',
          Ονοματεπώνυμο: data.name,
          Τηλέφωνο: data.phone,
          Email: data.email,
          Θέση: data.role,
          Περιοχή: data.city,
          Εμπειρία: data.experience,
          Μήνυμα: data.message,
        }),
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      // The relay answers 200 even when it refuses the submission, so the HTTP
      // status alone proves nothing. Trust the body, and treat anything that is
      // not an explicit success as a failure so we never fake a confirmation.
      const payload = await res.json().catch(() => ({}));
      const ok = payload.success === true || payload.success === 'true';
      if (!ok) throw new DeliveryError(payload.message || 'Ο relay απέρριψε την αίτηση.');

      showStatus('ok', `✅ <strong>Έγινε!</strong> Η καταχώρισή σου στάλθηκε στο ${EMAIL}. Θα επικοινωνήσουμε μαζί σου σύντομα.`);
      form.reset();
    } catch (err) {
      console.warn('Form relay failed, falling back to mail client:', err);
      showStatus('warn',
        '⚠️ Η αυτόματη αποστολή δεν ολοκληρώθηκε. Ανοίγουμε το email σου με τα στοιχεία έτοιμα — πάτησε αποστολή από εκεί, ή στείλε τα στο '
        + `<a href="mailto:${EMAIL}">${EMAIL}</a>.`);
      mailtoFallback(data);
    } finally {
      setBusy(false);
    }
  });

  // clear a field's error as soon as the visitor fixes it
  form.addEventListener('input', (e) => {
    if (e.target.id && e.target.getAttribute('aria-invalid') === 'true') setError(e.target.id, '');
  });
  form.addEventListener('change', (e) => {
    if (e.target.id && e.target.getAttribute('aria-invalid') === 'true') setError(e.target.id, '');
  });
}
