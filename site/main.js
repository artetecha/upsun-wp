/* artetecha/upsun-wp — site interactions.
   No dependencies, no external requests. */
(function () {
  'use strict';

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------- theme switcher */
  /* Cycles light → dark → system. "system" clears the override so the
     prefers-color-scheme media query (and live OS changes) take over.
     The stored value is applied pre-paint by the inline script in <head>. */
  var themeBtn = document.getElementById('theme-toggle');
  if (themeBtn) {
    var THEME_KEY = 'upsun-site-theme';
    var THEME_ORDER = ['light', 'dark', 'system'];
    var THEME_META = {
      light: { icon: '☀', label: 'light' },
      dark: { icon: '☾', label: 'dark' },
      system: { icon: '◐', label: 'auto' }
    };
    var themeIcon = themeBtn.querySelector('.theme-icon');
    var themeLabel = themeBtn.querySelector('.theme-label');
    var currentTheme = (function () {
      try {
        var t = localStorage.getItem(THEME_KEY);
        if (t === 'light' || t === 'dark') return t;
      } catch (e) { /* noop */ }
      return 'system';
    })();

    var applyTheme = function (t) {
      currentTheme = t;
      try {
        if (t === 'system') {
          document.documentElement.removeAttribute('data-theme');
          localStorage.removeItem(THEME_KEY);
        } else {
          document.documentElement.setAttribute('data-theme', t);
          localStorage.setItem(THEME_KEY, t);
        }
      } catch (e) { /* storage unavailable; theme still applies for this page */ }
      themeIcon.textContent = THEME_META[t].icon;
      themeLabel.textContent = THEME_META[t].label;
      themeBtn.setAttribute('title', 'Theme: ' + THEME_META[t].label + ' — click to switch');
    };

    applyTheme(currentTheme);
    themeBtn.addEventListener('click', function () {
      applyTheme(THEME_ORDER[(THEME_ORDER.indexOf(currentTheme) + 1) % THEME_ORDER.length]);
    });
  }

  /* ---------------------------------------------------- mobile nav */
  var toggle = document.querySelector('.nav-toggle');
  var links = document.getElementById('nav-links');
  if (toggle && links) {
    toggle.addEventListener('click', function () {
      var open = links.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    links.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        links.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---------------------------------------------------- copy buttons */
  document.querySelectorAll('[data-copy]').forEach(function (block) {
    var pre = block.querySelector('pre');
    if (!pre) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'copy-btn';
    btn.textContent = 'copy';
    btn.setAttribute('aria-label', 'Copy code to clipboard');
    btn.addEventListener('click', function () {
      var text = pre.textContent.replace(/^\$ /gm, '');
      var done = function () {
        btn.textContent = 'copied ✓';
        btn.classList.add('copied');
        setTimeout(function () {
          btn.textContent = 'copy';
          btn.classList.remove('copied');
        }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* noop */ }
        document.body.removeChild(ta);
        done();
      }
    });
    block.appendChild(btn);
  });

  /* ---------------------------------------------------- scroll reveal */
  var revealEls = document.querySelectorAll('.reveal');
  if (reducedMotion || !('IntersectionObserver' in window)) {
    revealEls.forEach(function (el) { el.classList.add('visible'); });
  } else {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { revealObserver.observe(el); });
  }

  /* ---------------------------------------------------- hero terminal */
  var body = document.getElementById('terminal-body');
  if (!body) return;

  // Script: array of steps. type:'cmd' is typed char by char; type:'out' prints line by line.
  var SCRIPT = [
    { type: 'cmd', text: 'wp upsun doctor' },
    { type: 'out', lines: [
      '<span class="tok-dim">Upsun · project abc123xyz · env feature/checkout (development)</span>',
      '<span class="tok-ok">✔</span> Object cache ....... Redis round-trip OK',
      '<span class="tok-ok">✔</span> Cron ............... runner configured, DISABLE_WP_CRON set',
      '<span class="tok-ok">✔</span> Cron heartbeat ..... last beat 12 min ago (hourly)',
      '<span class="tok-ok">✔</span> Writable mounts .... all declared paths mounted',
      '<span class="tok-ok">✔</span> Preview search ..... noindex active on this environment',
      '<span class="tok-ok">✔</span> Preview safety ..... sanitized on clone (stamp match)',
      '<span class="tok-ok">✔</span> Relationships ...... mysql ping OK · redis INFO OK',
      '<span class="tok-ok">✔</span> Disk usage ......... 34% of 5GB used',
      '<span class="tok-ok">Success:</span> 8 checks passed.',
      ''
    ]},
    { type: 'cmd', text: 'wp upsun info' },
    { type: 'out', lines: [
      '<span class="tok-dim">project</span>      abc123xyz',
      '<span class="tok-dim">environment</span>  feature/checkout <span class="tok-warn">(development)</span>',
      '<span class="tok-dim">branch</span>       feature/checkout',
      '<span class="tok-dim">route</span>        https://checkout-abc123.upsunapp.example/',
      ''
    ]}
  ];

  var PROMPT = '<span class="tok-p">$</span> ';

  function renderInstant() {
    var html = '';
    SCRIPT.forEach(function (step) {
      if (step.type === 'cmd') {
        html += '<div class="tline">' + PROMPT + step.text + '</div>';
      } else {
        step.lines.forEach(function (l) {
          html += '<div class="tline">' + (l || '&nbsp;') + '</div>';
        });
      }
    });
    html += '<div class="tline">' + PROMPT + '<span class="tcursor"></span></div>';
    body.innerHTML = html;
  }

  if (reducedMotion || !('IntersectionObserver' in window)) {
    renderInstant();
    return;
  }

  function addLine(html) {
    var div = document.createElement('div');
    div.className = 'tline';
    div.innerHTML = html || '&nbsp;';
    body.appendChild(div);
    return div;
  }

  function play() {
    var stepIdx = 0;

    function nextStep() {
      if (stepIdx >= SCRIPT.length) {
        addLine(PROMPT + '<span class="tcursor"></span>');
        return;
      }
      var step = SCRIPT[stepIdx++];
      if (step.type === 'cmd') {
        typeCommand(step.text, nextStep);
      } else {
        printLines(step.lines, 0, nextStep);
      }
    }

    function typeCommand(text, done) {
      var line = addLine(PROMPT + '<span class="typed"></span><span class="tcursor"></span>');
      var typed = line.querySelector('.typed');
      var i = 0;
      (function tick() {
        if (i < text.length) {
          typed.textContent += text.charAt(i++);
          setTimeout(tick, 34 + Math.floor(28 * ((i * 7) % 10) / 10));
        } else {
          setTimeout(function () {
            line.querySelector('.tcursor').remove();
            done();
          }, 320);
        }
      })();
    }

    function printLines(lines, i, done) {
      if (i >= lines.length) { done(); return; }
      addLine(lines[i]);
      setTimeout(function () { printLines(lines, i + 1, done); }, 120);
    }

    nextStep();
  }

  var started = false;
  var termObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting && !started) {
        started = true;
        termObserver.disconnect();
        setTimeout(play, 500);
      }
    });
  }, { threshold: 0.3 });
  termObserver.observe(body);
})();
