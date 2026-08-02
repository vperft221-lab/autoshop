// Small progressive enhancements (no framework, CSP-safe — no inline JS).
document.addEventListener('DOMContentLoaded', () => {
  // Auto-fill labour charge when a catalogue service is chosen.
  document.querySelectorAll('.js-service').forEach(sel => {
    sel.addEventListener('change', () => {
      const opt = sel.options[sel.selectedIndex];
      const charge = opt && opt.dataset.charge;
      const input = sel.parentNode.querySelector('.js-charge');
      if (input && charge && !input.value) input.value = charge;
    });
  });

  // Auto-dismiss flash messages after a few seconds.
  document.querySelectorAll('.flash').forEach(f => {
    setTimeout(() => {
      f.style.transition = 'opacity .4s';
      f.style.opacity = '0';
      setTimeout(() => f.remove(), 400);
    }, 4500);
  });
});

// Password show/hide toggle on the login page.
document.addEventListener('DOMContentLoaded', () => {
  const toggleBtn = document.getElementById('togglePwd');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const input = document.getElementById('password');
      if (input.type === 'password') {
        input.type = 'text';
        toggleBtn.textContent = '🙈';
      } else {
        input.type = 'password';
        toggleBtn.textContent = '👁';
      }
    });
  }
});

// Vehicle registration: reveal a free-text field when "Other" is picked for Make.
document.addEventListener('DOMContentLoaded', () => {
  const makeSelect = document.querySelector('select[name="make"]');
  const otherWrap = document.querySelector('.js-other-make');
  if (makeSelect && otherWrap) {
    const sync = () => { otherWrap.style.display = (makeSelect.value === '__other__') ? '' : 'none'; };
    makeSelect.addEventListener('change', sync);
    sync();
  }
});

// Appointments: only show vehicles that belong to the selected customer.
document.addEventListener('DOMContentLoaded', () => {
  const dataEl = document.getElementById('apptVehicleData');
  const custSelect = document.querySelector('.js-appt-customer');
  const vehSelect = document.querySelector('.js-appt-vehicle');
  if (!dataEl || !custSelect || !vehSelect) return;

  let byCustomer = {};
  try { byCustomer = JSON.parse(dataEl.dataset.vehicles || '{}'); } catch (e) { byCustomer = {}; }

  const refresh = () => {
    const cid = custSelect.value;
    vehSelect.innerHTML = '';
    const vehicles = cid ? (byCustomer[cid] || []) : [];
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = cid ? (vehicles.length ? '— Optional —' : '— No vehicles registered —') : '— Select customer first —';
    vehSelect.appendChild(placeholder);
    vehicles.forEach(v => {
      const o = document.createElement('option');
      o.value = v.id;
      o.textContent = v.label;
      vehSelect.appendChild(o);
    });
    vehSelect.disabled = !cid;
  };
  custSelect.addEventListener('change', refresh);
  refresh();
});

// Delegated click handling (replaces inline onclick, which CSP blocks).
document.addEventListener('click', (e) => {
  // Print invoice
  if (e.target.closest('.js-print')) {
    e.preventDefault();
    window.print();
    return;
  }
  // Manually close a flash message
  const fx = e.target.closest('.js-flash-close');
  if (fx) {
    e.preventDefault();
    const f = fx.closest('.flash');
    if (f) f.remove();
    return;
  }
  // Confirmation gate for links and submit buttons carrying data-confirm
  const c = e.target.closest('[data-confirm]');
  if (c && !window.confirm(c.getAttribute('data-confirm'))) {
    e.preventDefault();
    e.stopPropagation();
  }
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('.pwd-toggle');
    if (!btn) return;
    const input = document.getElementById(btn.dataset.target);
    if (!input) return;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.textContent = showing ? 'Show' : 'Hide';
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('.sms-history-toggle');
    if (!btn) return;
    const body = document.getElementById(btn.dataset.target);
    if (!body) return;
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    btn.classList.toggle('open', !isOpen);
});

document.addEventListener('DOMContentLoaded', () => {
    const thread = document.getElementById('msgThread');
    const form = document.getElementById('msgForm');
    if (!thread || !form) return;

    let lastSince = '1970-01-01 00:00:00';

    function render(messages) {
        messages.forEach(m => {
            const row = document.createElement('div');
            row.className = 'msg-row ' + (m.sender_type === 'customer' ? 'msg-from-customer' : 'msg-from-staff');
            const bubble = document.createElement('div');
            bubble.className = 'msg-bubble';
            bubble.textContent = m.body;
            const time = document.createElement('div');
            time.className = 'msg-time';
            time.textContent = m.created_at;
            row.appendChild(bubble);
            row.appendChild(time);
            thread.appendChild(row);
        });
        if (messages.length) thread.scrollTop = thread.scrollHeight;
    }

    async function poll() {
        try {
            const res = await fetch(thread.dataset.poll + '?since=' + encodeURIComponent(lastSince));
            const data = await res.json();
            if (data.messages && data.messages.length) {
                render(data.messages);
                lastSince = data.messages[data.messages.length - 1].created_at;
            }
        } catch (e) { /* silent retry on next interval */ }
    }

    poll();
    setInterval(poll, 4000);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const textarea = form.querySelector('textarea');
        const body = textarea.value.trim();
        if (!body) return;
        const fd = new FormData(form);
        const res = await fetch(form.dataset.endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            textarea.value = '';
            poll();
        }
    });
});
