/* Boarding House — interaksi & animasi ringan, tanpa dependensi eksternal */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /* ---------- Modal ------------------------------------------------- */
  window.openModal = function (id, data) {
    var m = document.getElementById(id);
    if (!m) return;
    if (m.parentNode !== document.body) {
      document.body.appendChild(m);
    }
    if (data) {
      Object.keys(data).forEach(function (k) {
        var el = m.querySelector('[data-f="' + k + '"]');
        if (!el) return;
        if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
          el.value = data[k];
          el.dispatchEvent(new Event('input'));
        } else {
          el.textContent = data[k];
        }
      });
    }
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    var f = m.querySelector('input:not([type=hidden]), select, textarea');
    if (f) setTimeout(function () { f.focus(); }, 120);
  };
  window.closeModal = function (id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
  };
  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
      e.target.classList.remove('open');
      document.body.style.overflow = '';
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $$('.modal.open').forEach(function (m) { m.classList.remove('open'); });
    document.body.style.overflow = '';
  });

  /* ---------- Konfirmasi aksi --------------------------------------- */
  $$('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  /* ---------- Indikator memuat pada submit --------------------------- */
  $$('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit], button:not([type])');
      if (b && !b.hasAttribute('data-confirm')) {
        setTimeout(function () { b.classList.add('loading'); }, 40);
      }
    });
  });

  /* ---------- Alert menghilang sendiri ------------------------------- */
  setTimeout(function () {
    $$('.alert').forEach(function (a, i) {
      setTimeout(function () {
        a.style.transition = 'opacity .5s, transform .5s';
        a.style.opacity = '0';
        a.style.transform = 'translateY(-8px)';
        setTimeout(function () { a.remove(); }, 520);
      }, i * 120);
    });
  }, 7000);

  /* ---------- Muncul saat di-scroll ---------------------------------
     Pengaman berlapis: konten TIDAK BOLEH tetap tersembunyi walau
     IntersectionObserver atau transisi CSS gagal berjalan.            */
  var revealables = $$('.reveal');
  var paksaTampil = function (el) {
    el.classList.add('in');
    // Matikan transisi dulu: di tab yang tidak dirender, transisi tidak pernah
    // maju sehingga nilai opacity membeku di 0 dan konten tetap tak terlihat.
    el.style.transition = 'none';
    el.style.opacity = '1';
    el.style.transform = 'none';
  };
  if (revealables.length) {
    if (reduce || !('IntersectionObserver' in window)) {
      revealables.forEach(paksaTampil);
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          en.target.classList.add('in');   // pakai animasi yang halus
          io.unobserve(en.target);
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px' });
      revealables.forEach(function (el) { io.observe(el); });
      setTimeout(function () { revealables.forEach(paksaTampil); }, 2000);
    }
  }

  /* ---------- Angka berhitung naik ---------------------------------- */
  function nilaiAkhir(el) {
    var target = parseFloat(el.getAttribute('data-count')) || 0;
    el.textContent = (el.getAttribute('data-prefix') || '') + target.toLocaleString('id-ID');
  }
  function countUp(el) {
    var target = parseFloat(el.getAttribute('data-count')) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var dur = 900, start = null;
    // requestAnimationFrame tidak berjalan di tab tersembunyi -> langsung tampilkan.
    if (reduce || document.visibilityState === 'hidden') { nilaiAkhir(el); return; }
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var v = Math.round(target * (1 - Math.pow(1 - p, 3)));
      el.textContent = prefix + v.toLocaleString('id-ID');
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
    // Pengaman: pastikan angka final tetap muncul walau animasi tersendat.
    setTimeout(function () { nilaiAkhir(el); }, dur + 400);
  }
  var counters = $$('[data-count]');
  if (counters.length) {
    if (!('IntersectionObserver' in window)) counters.forEach(countUp);
    else {
      var done = [];
      var ioc = new IntersectionObserver(function (es) {
        es.forEach(function (en) {
          if (!en.isIntersecting || done.indexOf(en.target) > -1) return;
          done.push(en.target); countUp(en.target); ioc.unobserve(en.target);
        });
      }, { threshold: 0.4 });
      counters.forEach(function (el) { ioc.observe(el); });
      // Pengaman: angka wajib tampil walau observer tidak pernah terpicu.
      setTimeout(function () {
        counters.forEach(function (el) {
          if (done.indexOf(el) === -1) { done.push(el); nilaiAkhir(el); }
        });
      }, 2000);
    }
  }

  /* ---------- Meteran tingkat hunian -------------------------------- */
  setTimeout(function () {
    $$('.kost-meter i').forEach(function (el) { el.style.width = (el.dataset.w || 0) + '%'; });
  }, 260);

  /* ---------- Topbar menempel & tombol ke atas ----------------------- */
  var topbar = $('.topbar');
  var totop = document.createElement('button');
  totop.className = 'totop no-print';
  totop.innerHTML = '↑';
  totop.title = 'Kembali ke atas';
  totop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
  document.body.appendChild(totop);
  window.addEventListener('scroll', function () {
    var y = window.scrollY;
    if (topbar) topbar.classList.toggle('stuck', y > 8);
    totop.classList.toggle('on', y > 420);
  }, { passive: true });

  /* ---------- Helpdesk WhatsApp -------------------------------------
     Label "Chat dengan Kami" tampil sebentar lalu menghilang otomatis
     supaya tidak menutupi tombol/tabel di baliknya untuk seterusnya.
     Muncul lagi sekilas saat tombolnya disentuh atau difokus. */
  var hd = $('#helpdesk');
  window.toggleHelpdesk = function (paksa) {
    if (!hd) return;
    var buka = typeof paksa === 'boolean' ? paksa : !hd.classList.contains('open');
    hd.classList.toggle('open', buka);
  };
  if (hd) {
    var bubble = hd.querySelector('.hd-bubble');
    var fab = hd.querySelector('.hd-fab');
    var timerSembunyi = null;

    var jadwalkanSembunyi = function (tunda) {
      clearTimeout(timerSembunyi);
      timerSembunyi = setTimeout(function () {
        if (bubble) bubble.classList.add('hd-hide');
      }, tunda);
    };
    var tampilkanSebentar = function () {
      if (bubble) bubble.classList.remove('hd-hide');
      jadwalkanSembunyi(2600);
    };

    jadwalkanSembunyi(5000); // tampil dulu sebentar saat halaman dibuka
    if (fab) {
      fab.addEventListener('mouseenter', tampilkanSebentar);
      fab.addEventListener('focus', tampilkanSebentar, true);
    }

    document.addEventListener('click', function (e) {
      if (hd.classList.contains('open') && !hd.contains(e.target)) hd.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hd.classList.remove('open');
    });
  }

  /* ---------- Sidebar mobile ---------------------------------------- */
  var sidebar = $('#sidebar');
  if (sidebar) {
    var scrim = document.createElement('div');
    scrim.className = 'scrim';
    document.body.appendChild(scrim);
    window.toggleSidebar = function () {
      var on = sidebar.classList.toggle('open');
      scrim.classList.toggle('on', on);
    };
    scrim.addEventListener('click', function () {
      sidebar.classList.remove('open');
      scrim.classList.remove('on');
    });
  }

  /* ---------- Lihat / sembunyikan kata sandi -------------------------
     Ikonnya berupa dua SVG yang ditukar lewat kelas CSS. Menimpa isi
     tombol dengan teks akan menghapus SVG-nya, jadi jangan lakukan itu. */
  $$('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var inp = document.getElementById(btn.getAttribute('data-toggle'));
      if (!inp) return;
      var tampil = inp.type === 'password';
      inp.type = tampil ? 'text' : 'password';
      btn.classList.toggle('on', tampil);
      btn.setAttribute('aria-label', tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
      inp.focus();
    });
  });

  /* ---------- Meteran kekuatan kata sandi ---------------------------- */
  var pw = $('input[name=password]');
  var meter = $('.meter');
  if (pw && meter) {
    pw.addEventListener('input', function () {
      var v = pw.value, s = 0;
      if (v.length >= 6) s++;
      if (v.length >= 10) s++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
      if (/\d/.test(v) || /[^\w]/.test(v)) s++;
      meter.className = 'meter' + (v ? ' m' + Math.max(1, s) : '');
    });
  }

  /* ---------- Normalisasi input nomor HP ----------------------------- */
  $$('input[name=no_hp], input[name=identitas], input[name=tujuan]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/[^\d+]/g, '');
      if (v.indexOf('+') > 0) v = v.replace(/\+/g, '');
      inp.value = v;
    });
  });

  /* ---------- Pratinjau gambar sebelum diunggah ---------------------- */
  $$('input[type=file][data-preview]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var box = document.getElementById(inp.getAttribute('data-preview'));
      if (!box || !inp.files[0]) return;
      box.innerHTML = '<img src="' + URL.createObjectURL(inp.files[0]) +
        '" style="max-height:190px;border-radius:12px;margin-top:10px;box-shadow:var(--shadow-1)">';
      box.firstChild.classList = 'pop-in';
    });
  });

  /* ---------- Pencarian langsung pada tabel / katalog ---------------- */
  $$('[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var kw = inp.value.toLowerCase().trim();
      var found = 0;
      $$(inp.getAttribute('data-filter')).forEach(function (row) {
        var hit = row.textContent.toLowerCase().indexOf(kw) > -1;
        row.style.display = hit ? '' : 'none';
        if (hit) found++;
      });
      var note = document.getElementById(inp.getAttribute('data-count-into') || '');
      if (note) note.textContent = found + ' hasil';
    });
  });

  /* ---------- Status kamar real-time (polling 15 detik) -------------- */
  var grid = document.getElementById('katalog');
  if (grid) {
    setInterval(function () {
      fetch(grid.dataset.api, { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.ok) return;
          res.data.forEach(function (k) {
            var card = grid.querySelector('[data-room="' + k.id + '"]');
            if (!card) return;
            var st = card.querySelector('.room-stat');
            if (st && st.dataset.status !== k.status) {
              st.outerHTML = k.badge;
              var bt = card.querySelector('.act');
              if (bt) bt.outerHTML = k.tombol;
              card.classList.remove('flash');
              void card.offsetWidth;
              card.classList.add('flash');
            }
          });
          var c = document.getElementById('sisa-kamar');
          if (c) c.textContent = res.tersedia;
        })
        .catch(function () { /* diam saja bila jaringan bermasalah */ });
    }, 15000);
  }

  /* ---------- Pratinjau rupiah pada input harga ---------------------- */
  $$('[data-rupiah]').forEach(function (inp) {
    var out = document.getElementById(inp.getAttribute('data-rupiah'));
    var show = function () {
      if (!out) return;
      var n = parseFloat(inp.value || '0');
      out.textContent = isNaN(n) || !n ? '' : 'Rp ' + n.toLocaleString('id-ID');
    };
    inp.addEventListener('input', show);
    show();
  });
})();
