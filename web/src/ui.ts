import type { Typo3Data, Verdict, AffectingAdvisory, Lang, MajorInfo, Tier } from './types';
import { computeVerdict, staleCheckedAt } from './verdict';
import { parseVersion, majorKey, compareVersions } from './version';
import { severityCounts, fixSplit, splitAdvisoryTitle, supportTimeline } from './format';
import { strings, type Strings } from './i18n';

// Long advisory lists collapse to the most severe entries; the rest reveal on demand.
const COLLAPSE_ABOVE = 10;
const VISIBLE_WHEN_COLLAPSED = 8;

const DEV_COMMAND = 'composer require --dev plan2net/typo3-update-check';

// One mark per tone rather than one per tier: the tier word already names the state.
const TONE_ICON: Record<Tone, string> = {
  attention:
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
  fine:
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>',
  state:
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
};

const CHEVRON =
  '<svg class="chev" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

type Tone = 'attention' | 'fine' | 'state';

const TONE: Record<Tier, Tone> = {
  'critical-missing-fix': 'attention',
  'critical-unfixed': 'attention',
  'critical-eol': 'attention',
  'critical-elts-only': 'state',
  'soon-support-ending': 'state',
  'review-optional': 'state',
  'behind-maintenance': 'state',
  'stale-data': 'state',
  'unknown-version': 'state',
  'all-good': 'fine',
};

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));
}

// Baked from third-party data on a public page: only allow http(s) links (no javascript:/data:).
function safeUrl(url: string): string {
  return /^https?:\/\//i.test(url) ? url : '#';
}

function advisoryItem(a: AffectingAdvisory, lang: Lang, m: Strings, hidden: boolean): string {
  const exp = a.advisory.explanation?.[lang];
  const sev = escapeHtml(a.advisory.severity);
  const { advisoryId, title } = splitAdvisoryTitle(a.advisory.title);
  const impact = exp ? escapeHtml(exp.plainImpact) : '';
  const urgency = exp ? escapeHtml(exp.urgency) : '';
  const caveat = a.optional
    ? `<p class="caveat">${escapeHtml(m.ui.onlyIfUses)} ${escapeHtml(a.advisory.package)}</p>`
    : '';
  // External link: accessible name conveys purpose + "opens in a new tab" (§11a).
  const cveLabel = a.advisory.cve ?? a.advisory.id;
  const linkName = `${m.ui.officialAdvisory} (${cveLabel}), ${m.ui.opensNewTab}`;
  const meta = [
    advisoryId ? `<code>${escapeHtml(advisoryId)}</code>` : '',
    `<a href="${escapeHtml(safeUrl(a.advisory.link))}" target="_blank" rel="noopener" aria-label="${escapeHtml(linkName)}">${escapeHtml(m.ui.officialAdvisory)}</a>`,
  ].filter(Boolean).join('<span aria-hidden="true">·</span>');

  return `<li class="advisory" data-severity="${sev}"${hidden ? ' hidden' : ''}>
      <details>
        <summary>
          <span class="badge">${escapeHtml(m.severityLabel(a.advisory.severity))}</span>
          <span class="advisory-title">${escapeHtml(title)}</span>
          ${CHEVRON}
        </summary>
        <div class="advisory-body">
          ${impact ? `<p>${impact}</p>` : ''}
          ${urgency ? `<p class="urgency">${urgency}</p>` : ''}
          ${caveat}
          <div class="advisory-meta">${meta}</div>
        </div>
      </details>
    </li>`;
}

function statPanel(items: AffectingAdvisory[], version: string, tier: Tier, m: Strings): string {
  if (!items.length) {
    if (tier !== 'all-good') return '';
    return `<aside class="stat">
        <p class="stat-label">${escapeHtml(m.ui.openAdvisories)}</p>
        <p class="stat-num">0</p>
        <p class="stat-cap">${escapeHtml(m.nothingAffects(version))}</p>
      </aside>`;
  }

  const counts = severityCounts(items.map((a) => a.advisory.severity));
  const bar = counts
    .map((c) => `<span class="s-${escapeHtml(c.severity)}" style="flex:${c.count}"></span>`)
    .join('');
  const key = counts
    .map((c) => `<li><i class="s-${escapeHtml(c.severity)}"></i><b>${c.count}</b> ${escapeHtml(m.severityLabel(c.severity))}</li>`)
    .join('');

  const { free, eltsOnly } = fixSplit(items);
  let split = '';
  if (free > 0 && eltsOnly > 0) split = m.splitBoth(free, eltsOnly);
  else if (free === 0 && eltsOnly > 0) split = m.splitEltsOnly(eltsOnly);
  else if (free > 0) split = m.splitAllFree(free);

  return `<aside class="stat">
      <p class="stat-label">${escapeHtml(m.ui.openAdvisories)}</p>
      <p class="stat-num">${items.length}</p>
      <div class="sevbar" aria-hidden="true">${bar}</div>
      <ul class="sevkey">${key}</ul>
      ${split ? `<p class="split">${escapeHtml(split)}</p>` : ''}
    </aside>`;
}

function lifecycle(major: MajorInfo, mk: string, version: string, m: Strings, now: Date): string {
  const release = major.releases.find((r) => r.version === version);
  const timeline = supportTimeline(major, release?.date ?? null, now);
  if (!timeline) return '';

  const ended = timeline.monthsToMaintained < 0;
  const summary = ended
    ? m.lifecycleEnded(Math.abs(timeline.monthsToMaintained), major.eltsUntil)
    : m.lifecycleRunning(timeline.monthsToMaintained, major.eltsUntil);

  const firstDated = major.releases
    .filter((r) => r.date !== null)
    .reduce((oldest, r) => (r.date! < oldest ? r.date! : oldest), major.releases.find((r) => r.date)!.date!);

  const alt = m.timelineAlt({
    major: mk,
    firstIso: firstDated,
    maintainedIso: major.maintainedUntil,
    eltsIso: major.eltsUntil,
    version,
    releaseIso: release!.date!,
    ageMonths: timeline.releaseAgeMonths,
  });

  const free = timeline.freePercent.toFixed(1);
  return `<div class="lifecycle">
      <div class="lifecycle-head">
        <h3>${escapeHtml(m.lifecycleTitle(mk))}</h3>
        <p>${escapeHtml(summary)}</p>
      </div>
      <div class="rail" role="img" aria-label="${escapeHtml(alt)}">
        <div class="flagrow">
          <span class="flag flag-today" style="left:${timeline.todayPercent.toFixed(1)}%">${escapeHtml(m.ui.today)}</span>
        </div>
        <div class="rail-track">
          <div class="zone zone-free" style="width:${free}%"><span>${escapeHtml(m.ui.zoneFree)}</span></div>
          <div class="zone zone-elts" style="width:${(100 - timeline.freePercent).toFixed(1)}%"><span>${escapeHtml(m.ui.zoneElts)}</span></div>
        </div>
        <div class="flagrow">
          <span class="flag flag-rel" style="left:${timeline.releasePercent.toFixed(1)}%">${escapeHtml(m.releasedFlag(version, timeline.releaseAgeMonths))}</span>
        </div>
        <div class="scale">
          <span class="s-start">${escapeHtml(fmtScale(firstDated))}</span>
          <span class="s-mid" style="left:${free}%">${escapeHtml(fmtScale(major.maintainedUntil))}</span>
          <span class="s-end">${escapeHtml(fmtScale(major.eltsUntil))}</span>
        </div>
      </div>
    </div>`;
}

// Year alone keeps the three scale labels from colliding on a narrow screen.
function fmtScale(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : `${d.getUTCFullYear()}`;
}

function devHint(m: Strings): string {
  return `<p class="dev-hint">${escapeHtml(m.ui.devHintLead)}
      <code>${escapeHtml(DEV_COMMAND)}</code>
      ${escapeHtml(m.ui.devHintTail)}</p>`;
}

function answerBand(v: Verdict, m: Strings, stat: string): string {
  const tone = TONE[v.tier];
  const concerns = v.concerns.length
    ? `<ul class="concerns">${v.concerns.map((c) => `<li>${escapeHtml(c)}</li>`).join('')}</ul>`
    : '';
  const share = v.tier !== 'unknown-version'
    ? `<div class="acts">
         <button type="button" class="copy-link">${escapeHtml(m.ui.copyLink)}</button>
         <p class="copy-status" role="status"></p>
       </div>`
    : '';

  return `<div class="answer${stat ? '' : ' answer--solo'}" data-tone="${tone}">
      <div class="answer-main">
        <p class="tier">${TONE_ICON[tone]}${escapeHtml(m.tierLabel[v.tier])}</p>
        <h2>${escapeHtml(v.headline)}</h2>
        <p class="detail">${escapeHtml(v.detail)}</p>
        ${concerns}
        ${share}
      </div>
      ${stat}
    </div>`;
}

function group(items: AffectingAdvisory[], cls: string, heading: string, lang: Lang, m: Strings): string {
  if (!items.length) return '';
  const collapsed = items.length > COLLAPSE_ABOVE;
  const hiddenCount = items.length - VISIBLE_WHEN_COLLAPSED;
  const showAll = collapsed
    ? `<button type="button" class="show-all">${escapeHtml(m.showRemaining(hiddenCount))}</button>`
    : '';
  const summary = severityCounts(items.map((a) => a.advisory.severity))
    .map((c) => `${c.count} ${m.severityLabel(c.severity)}`)
    .join(' · ');

  return `<section class="group ${cls}">
      <div class="group-head">
        <h3>${escapeHtml(heading)}</h3>
        <span class="severity-summary">${items.length} · ${escapeHtml(summary)}</span>
      </div>
      <ul class="advisories">${items.map((a, i) => advisoryItem(a, lang, m, collapsed && i >= VISIBLE_WHEN_COLLAPSED)).join('')}</ul>
      ${showAll}
    </section>`;
}

function renderVerdict(v: Verdict, version: string, data: Typo3Data, lang: Lang, m: Strings, now: Date): string {
  const core = v.affecting.filter((a) => !a.optional);
  const optional = v.affecting.filter((a) => a.optional);
  const major = data.majors[majorKey(version)];
  // Core only: folding in the conditional set would contradict the headline count.
  const stat = statPanel(core, version, v.tier, m);

  return `<div class="verdict" data-tier="${v.tier}">
      ${answerBand(v, m, stat)}
      ${major ? lifecycle(major, majorKey(version), version, m, now) : ''}
      ${group(core, 'affects', m.ui.affects, lang, m)}
      ${group(optional, 'may-apply', m.ui.mayApply, lang, m)}
      ${devHint(m)}
    </div>`;
}

function renderFor(raw: string, hasElts: boolean, data: Typo3Data, lang: Lang): string {
  const m = strings(lang);
  const now = new Date();
  // A security verdict needs the exact patch; major.minor only gets support info.
  if (!parseVersion(raw)) {
    const mk = majorKey(raw);
    const major = data.majors[mk];
    const t = major ? m.unknownMajor(mk, major.maintainedUntil, major.eltsUntil) : m.unknownVersion();
    // Support dates come straight from the dataset, so they need the same staleness disclaimer
    // an exact-version verdict would carry.
    const staleSince = staleCheckedAt(data, now);
    const concerns = staleSince !== null ? [m.concernMaybeNewer(staleSince)] : [];
    const verdict: Verdict = {
      tier: 'unknown-version',
      supportPhase: 'unknown',
      recommendedVersion: null,
      headline: t.headline,
      detail: t.detail,
      affecting: [],
      concerns,
    };
    return `<div class="verdict" data-tier="unknown-version">${answerBand(verdict, m, '')}</div>`;
  }
  return renderVerdict(computeVerdict(raw, hasElts, data, now, lang), raw, data, lang, m, now);
}

function readLang(): Lang {
  return new URLSearchParams(location.search).get('lang') === 'de' ? 'de' : 'en';
}

function localiseChrome(root: Document, lang: Lang): void {
  const m = strings(lang);
  root.documentElement.lang = lang;
  root.title = m.ui.title;
  const set = (id: string, text: string): void => {
    const el = root.getElementById(id);
    if (el) el.textContent = text;
  };
  set('brand-text', m.ui.brand);
  set('app-title', m.ui.heroTitle);
  set('app-tagline', m.ui.tagline);
  set('pick-label', m.ui.pickLabel);
  set('version-select-label', m.ui.yourVersion);
  set('elts-label', m.ui.eltsShort);
  set('made-by-text', m.ui.madeBy);
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

// Ascending, so the segmented control reads left-to-right like a version line.
function sortedMajorKeys(data: Typo3Data): string[] {
  return Object.keys(data.majors).sort((a, b) => Number(a) - Number(b));
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
      if (r.version === major.latestFree) tags.push(m.ui.tagLatest);
      if (r.type === 'security') tags.push(m.ui.tagSecurity);
      if (r.elts) tags.push(m.ui.tagElts);
      const label = tags.length ? `${r.version} — ${tags.join(' · ')}` : r.version;
      return `<option value="${escapeHtml(r.version)}">${escapeHtml(label)}</option>`;
    })
    .join('');
}

export function initUi(root: Document, data: Typo3Data): void {
  const majorSeg = root.getElementById('major-seg') as HTMLElement;
  const versionSelect = root.getElementById('version') as HTMLSelectElement;
  const elts = root.getElementById('has-elts') as HTMLInputElement;
  const eltsSwitch = root.getElementById('elts-switch') as HTMLElement;
  const eltsNote = root.getElementById('elts-note') as HTMLElement;
  const result = root.getElementById('result') as HTMLElement;
  const announce = root.getElementById('result-announce') as HTMLElement;
  let lang = readLang();

  const majors = sortedMajorKeys(data);
  // Default to the newest line even though the control lists them ascending.
  let currentMajor = majors[majors.length - 1] ?? '';

  const paintSegments = (): void => {
    majorSeg.innerHTML = majors
      .map((mk) => `<button type="button" data-major="${escapeHtml(mk)}" aria-pressed="${mk === currentMajor}">` +
        `<span class="vh">TYPO3 </span>${escapeHtml(mk)}</button>`)
      .join('');
  };

  const populateVersions = (mk: string): void => {
    versionSelect.innerHTML = releaseOptions(data, mk, lang);
  };

  const writeUrl = (raw: string, hasElts: boolean): void => {
    const params = new URLSearchParams({ v: raw, elts: hasElts ? '1' : '0', lang });
    history.replaceState(null, '', `${location.pathname}?${params.toString()}`);
  };

  const show = (raw: string, hasElts: boolean): void => {
    // A line still in free maintenance has no ELTS yet: swap the switch for a note, but keep its
    // state (and the URL flag) so it still applies after switching back to an ELTS line.
    const mk = majorKey(raw);
    const major = data.majors[mk];
    const eltsApplies = !major || new Date(major.maintainedUntil) <= new Date();
    eltsSwitch.hidden = !eltsApplies;
    eltsNote.hidden = eltsApplies;
    eltsNote.textContent = major && !eltsApplies ? strings(lang).eltsNotYet(mk, major.maintainedUntil) : '';
    result.innerHTML = renderFor(raw, hasElts, data, lang);
    const headline = result.querySelector('h2')?.textContent ?? '';
    const detail = result.querySelector('.detail')?.textContent ?? '';
    announce.textContent = `${headline} ${detail}`.trim();
    writeUrl(raw, hasElts); // shareable: version + ELTS + language (§2/§10)
  };

  paintSegments();
  populateVersions(currentMajor);
  localiseChrome(root, lang);

  majorSeg.addEventListener('click', (e) => {
    const button = (e.target as HTMLElement).closest<HTMLButtonElement>('button[data-major]');
    if (!button || button.dataset.major === undefined) return;
    currentMajor = button.dataset.major;
    paintSegments();
    populateVersions(currentMajor);
    // The button was replaced by paintSegments, so put focus back where it was.
    majorSeg.querySelector<HTMLButtonElement>(`[data-major="${currentMajor}"]`)?.focus();
    show(versionSelect.value, elts.checked);
  });

  // No submit step: the dataset is already local, so there is nothing to wait for.
  versionSelect.addEventListener('change', () => show(versionSelect.value, elts.checked));
  elts.addEventListener('change', () => show(versionSelect.value, elts.checked));

  // Copy-link + show-all buttons (event delegation — re-rendered with every verdict).
  result.addEventListener('click', (e) => {
    const target = e.target as HTMLElement;
    if (target.classList.contains('copy-link')) {
      void navigator.clipboard?.writeText(location.href);
      const status = target.parentElement?.querySelector('.copy-status');
      if (status) status.textContent = strings(lang).ui.copied;
    }
    if (target.classList.contains('show-all')) {
      const advisoryGroup = target.closest('.group');
      const firstRevealed = advisoryGroup?.querySelector<HTMLElement>('li[hidden] summary');
      advisoryGroup?.querySelectorAll('li[hidden]').forEach((item) => item.removeAttribute('hidden'));
      firstRevealed?.focus(); // keep keyboard focus in the list once the button disappears
      target.remove();
    }
  });

  // Language toggle: re-localise chrome, re-render the current result, keep the query.
  root.getElementById('lang-toggle')?.addEventListener('click', (e) => {
    const chosen = (e.target as HTMLElement).dataset.lang as Lang | undefined;
    if (chosen !== 'en' && chosen !== 'de') return;
    lang = chosen;
    localiseChrome(root, lang);
    const selected = versionSelect.value;
    populateVersions(currentMajor); // re-localise the option tags
    versionSelect.value = selected;
    if (versionSelect.value) show(versionSelect.value, elts.checked);
    else writeUrl(versionSelect.value, elts.checked);
  });

  // Deep link: prefill + auto-run from ?v=&elts=&lang=. Without one, fall back
  // to the newest release rather than an empty pane.
  const linked = new URLSearchParams(location.search).get('v');
  if (linked) {
    // Canonicalise ("v13.4.35" -> "13.4.35") so the select, timeline and URL all match the dataset.
    const deepLinked = parseVersion(linked)?.join('.') ?? linked;
    const mk = majorKey(deepLinked);
    if (data.majors[mk]) {
      currentMajor = mk;
      paintSegments();
      populateVersions(mk);
      versionSelect.value = deepLinked;
    }
    elts.checked = new URLSearchParams(location.search).get('elts') === '1';
    show(deepLinked, elts.checked);
    return;
  }
  show(versionSelect.value, elts.checked);
}
