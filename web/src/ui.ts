import type { Typo3Data, Verdict, AffectingAdvisory, Lang } from './types';
import { computeVerdict, staleCheckedAt } from './verdict';
import { parseVersion, majorKey, compareVersions } from './version';
import { strings, type Strings } from './i18n';

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));
}

// Baked from third-party data on a public page: only allow http(s) links (no javascript:/data:).
function safeUrl(url: string): string {
  return /^https?:\/\//i.test(url) ? url : '#';
}

function advisoryItem(a: AffectingAdvisory, lang: Lang, m: Strings): string {
  const exp = a.advisory.explanation?.[lang];
  const sev = escapeHtml(a.advisory.severity);
  const title = escapeHtml(a.advisory.title);
  const impact = exp ? escapeHtml(exp.plainImpact) : '';
  const urgency = exp ? escapeHtml(exp.urgency) : '';
  const caveat = a.optional
    ? `<p class="caveat">${escapeHtml(m.ui.onlyIfUses)} ${escapeHtml(a.advisory.package)}</p>`
    : '';
  // External link: accessible name conveys purpose + "opens in a new tab" (§11a).
  const cveLabel = a.advisory.cve ?? a.advisory.id;
  const linkName = `${m.ui.officialAdvisory} (${cveLabel}), ${m.ui.opensNewTab}`;
  return `<li class="advisory" data-severity="${sev}">
      <strong>${title}</strong> <span class="badge">${sev}</span>
      ${impact ? `<p>${impact}</p>` : ''}
      ${urgency ? `<p class="urgency">${urgency}</p>` : ''}
      ${caveat}
      <a href="${escapeHtml(safeUrl(a.advisory.link))}" target="_blank" rel="noopener" aria-label="${escapeHtml(linkName)}">${escapeHtml(m.ui.officialAdvisory)}</a>
    </li>`;
}

function renderVerdict(v: Verdict, lang: Lang, m: Strings): string {
  const core = v.affecting.filter((a) => !a.optional);
  const optional = v.affecting.filter((a) => a.optional);
  const concerns = v.concerns.length
    ? `<ul class="concerns">${v.concerns.map((c) => `<li>${escapeHtml(c)}</li>`).join('')}</ul>`
    : '';
  const group = (items: AffectingAdvisory[], cls: string, heading: string): string =>
    items.length
      ? `<section class="group ${cls}"><h3>${escapeHtml(heading)}</h3>` +
        `<ul class="advisories">${items.map((a) => advisoryItem(a, lang, m)).join('')}</ul></section>`
      : '';
  const share = v.tier !== 'unknown-version'
    ? `<div class="share">
         <button type="button" class="copy-link">${escapeHtml(m.ui.copyLink)}</button>
         <p class="status" role="status"></p>
         <p class="hint">${escapeHtml(m.ui.shareHint)}</p>
       </div>`
    : '';
  return `
    <div class="verdict" data-tier="${v.tier}">
      <p class="tier">${escapeHtml(m.tierLabel[v.tier])}</p>
      <h2>${escapeHtml(v.headline)}</h2>
      <p class="detail${concerns ? ' has-concerns' : ''}">${escapeHtml(v.detail)}</p>
      ${concerns}
      ${group(core, 'affects', m.ui.affects)}
      ${group(optional, 'may-apply', m.ui.mayApply)}
      ${share}
    </div>`;
}

function renderFor(raw: string, hasElts: boolean, data: Typo3Data, lang: Lang): string {
  const m = strings(lang);
  // A security verdict needs the exact patch; major.minor only gets support info.
  if (!parseVersion(raw)) {
    const mk = majorKey(raw.replace(/^v/i, ''));
    const major = data.majors[mk];
    const t = major ? m.unknownMajor(mk, major.maintainedUntil, major.eltsUntil) : m.unknownVersion();
    // Support dates come straight from the dataset, so they need the same staleness disclaimer
    // an exact-version verdict would carry.
    const staleSince = staleCheckedAt(data, new Date());
    const concerns = staleSince !== null
      ? `<ul class="concerns"><li>${escapeHtml(m.concernMaybeNewer(staleSince))}</li></ul>`
      : '';
    return `<div class="verdict" data-tier="unknown-version">
        <p class="tier">${escapeHtml(m.tierLabel['unknown-version'])}</p>
        <h2>${escapeHtml(t.headline)}</h2>
        <p class="detail${concerns ? ' has-concerns' : ''}">${escapeHtml(t.detail)}</p>
        ${concerns}
      </div>`;
  }
  return renderVerdict(computeVerdict(raw, hasElts, data, new Date(), lang), lang, m);
}

function readLang(): Lang {
  return new URLSearchParams(location.search).get('lang') === 'de' ? 'de' : 'en';
}

function localiseChrome(root: Document, lang: Lang): void {
  const m = strings(lang);
  root.documentElement.lang = lang;
  const set = (id: string, text: string): void => {
    const el = root.getElementById(id);
    if (el) el.textContent = text;
  };
  set('app-title', m.ui.title);
  set('app-tagline', m.ui.tagline);
  set('version-label', m.ui.versionLabel);
  set('version-hint', m.ui.versionHint);
  set('major-label', m.ui.majorLabel);
  set('version-select-label', m.ui.yourVersion);
  set('elts-label', m.ui.eltsLabel);
  set('check-button', m.ui.check);
  // Landmark aria-labels are read by screen readers, so localise them too (data-i18n-label -> UiLabels key).
  root.querySelectorAll<HTMLElement>('[data-i18n-label]').forEach((el) => {
    const key = el.dataset.i18nLabel as keyof typeof m.ui | undefined;
    const value = key ? m.ui[key] : undefined;
    if (typeof value === 'string') el.setAttribute('aria-label', value);
  });
  root.querySelectorAll('#lang-toggle button').forEach((b) => {
    const el = b as HTMLButtonElement;
    const active = el.dataset.lang === lang;
    el.classList.toggle('active', active);
    el.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
}

// Highest-major-first so the most relevant line is the default.
function sortedMajorKeys(data: Typo3Data): string[] {
  return Object.keys(data.majors).sort((a, b) => Number(b) - Number(a));
}

// Releases newest-first; ELTS releases tagged so a free user can tell them apart.
function releaseOptions(data: Typo3Data, mk: string, lang: Lang): string {
  const m = strings(lang);
  const major = data.majors[mk];
  if (!major) return '';
  return [...major.releases]
    .sort((a, b) => compareVersions(b.version, a.version))
    .map((r) => {
      const tags: string[] = [];
      if (r.version === major.latestElts) tags.push(m.ui.tagLatest);
      if (r.type === 'security') tags.push(m.ui.tagSecurity);
      if (r.elts) tags.push(m.ui.tagElts);
      const label = tags.length ? `${r.version} — ${tags.join(' · ')}` : r.version;
      return `<option value="${escapeHtml(r.version)}">${escapeHtml(label)}</option>`;
    })
    .join('');
}

export function initUi(root: Document, data: Typo3Data): void {
  const form = root.getElementById('check-form') as HTMLFormElement;
  const majorSelect = root.getElementById('major') as HTMLSelectElement;
  const versionSelect = root.getElementById('version') as HTMLSelectElement;
  const elts = root.getElementById('has-elts') as HTMLInputElement;
  const result = root.getElementById('result') as HTMLElement;
  let lang = readLang();

  const majors = sortedMajorKeys(data);
  majorSelect.innerHTML = majors
    .map((mk) => `<option value="${escapeHtml(mk)}">TYPO3 ${escapeHtml(mk)}</option>`)
    .join('');

  const populateVersions = (mk: string): void => {
    versionSelect.innerHTML = releaseOptions(data, mk, lang);
  };
  populateVersions(majorSelect.value);

  const currentVersion = (): string => versionSelect.value;

  const writeUrl = (raw: string, hasElts: boolean): void => {
    const params = new URLSearchParams({ v: raw, elts: hasElts ? '1' : '0', lang });
    history.replaceState(null, '', `${location.pathname}?${params.toString()}`);
  };
  const show = (raw: string, hasElts: boolean): void => {
    result.innerHTML = renderFor(raw, hasElts, data, lang);
    writeUrl(raw, hasElts); // shareable: version + ELTS + language (§2/§10)
  };

  localiseChrome(root, lang);

  // Picking a line repopulates the version list (mockup: line buttons → patch select).
  majorSelect.addEventListener('change', () => {
    populateVersions(majorSelect.value);
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    show(currentVersion(), elts.checked);
  });

  // Copy-link button (event delegation — re-rendered with every verdict).
  result.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    if (target.classList.contains('copy-link')) {
      void navigator.clipboard?.writeText(location.href);
      const status = target.parentElement?.querySelector('.status');
      if (status) status.textContent = strings(lang).ui.copied;
    }
  });

  // Language toggle: re-localise chrome, re-render the current result, keep the query.
  root.getElementById('lang-toggle')?.addEventListener('click', (e) => {
    const chosen = (e.target as HTMLElement).dataset.lang as Lang | undefined;
    if (chosen !== 'en' && chosen !== 'de') return;
    lang = chosen;
    localiseChrome(root, lang);
    const selected = currentVersion();
    populateVersions(majorSelect.value); // re-localise the option tags
    versionSelect.value = selected;
    const raw = currentVersion();
    if (raw) show(raw, elts.checked);
    else writeUrl(raw, elts.checked);
  });

  // Deep link: prefill + auto-run from ?v=&elts=&lang=.
  const deepLinked = new URLSearchParams(location.search).get('v');
  if (deepLinked) {
    const mk = majorKey(deepLinked.replace(/^v/i, ''));
    if (data.majors[mk]) {
      majorSelect.value = mk;
      populateVersions(mk);
      versionSelect.value = deepLinked;
    }
    elts.checked = new URLSearchParams(location.search).get('elts') === '1';
    show(deepLinked, elts.checked);
  }
}
