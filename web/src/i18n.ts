import type { Lang, Tier } from './types';
import { fmtDate, plural } from './format';

interface TierText {
  headline: string;
  detail: string;
}

export interface UiLabels {
  title: string;
  tagline: string;
  versionLabel: string;
  versionHint: string;
  majorLabel: string;
  yourVersion: string;
  tagLatest: string;
  tagSecurity: string;
  tagElts: string;
  eltsLabel: string;
  check: string;
  affects: string;
  mayApply: string;
  copyLink: string;
  copied: string;
  shareHint: string;
  officialAdvisory: string;
  opensNewTab: string;
  onlyIfUses: string;
  loadError: string;
  langNavLabel: string;
  resultLabel: string;
  resourcesLabel: string;
  madeBy: string;
}

export interface Strings {
  tierLabel: Record<Tier, string>;
  missingFix(version: string, target: string, count: number, severity: string | null, hasElts: boolean): TierText;
  missingFixStale(version: string, count: number, severity: string | null): TierText;
  unfixed(version: string, major: string, count: number, severity: string | null): TierText;
  eltsOnly(major: string, gated: number): TierText;
  eol(major: string, eltsUntilIso: string): TierText;
  soon(major: string, endsIso: string, hasElts: boolean): TierText;
  reviewOptional(count: number): TierText;
  behind(version: string, target: string): TierText;
  allGood(hasElts: boolean): TierText;
  stale(updatedIso: string): TierText;
  unknownVersion(): TierText;
  unknownMajor(major: string, maintainedIso: string, eltsIso: string): TierText;
  concernOptional(count: number, packages: string[]): string;
  concernSeparateUnfixed(): string;
  concernStale(updatedIso: string): string;
  concernMaybeNewer(updatedIso: string): string;
  concernEltsGatedRemain(count: number): string;
  concernAlsoBehind(count: number): string;
  concernNewerMajor(major: number): string;
  severityLabel(severity: string): string;
  showAllAdvisories(total: number): string;
  ui: UiLabels;
}

const EN: Strings = {
  tierLabel: {
    'critical-missing-fix': 'Critical', 'critical-unfixed': 'Critical',
    'critical-elts-only': 'Critical', 'critical-eol': 'Critical',
    'soon-support-ending': 'Update soon', 'review-optional': 'Review',
    'behind-maintenance': 'Update advised', 'all-good': 'All good',
    'stale-data': 'Unconfirmed', 'unknown-version': 'Check the version',
  },
  missingFix: (version, target, count, severity, hasElts) => ({
    headline: `Update to TYPO3 ${target} now.`,
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? `, including a ${severity}-severity one` : ''}. ` +
      `${hasElts ? 'A patched release is available.' : 'A free patched release is available.'}`,
  }),
  // Stale variant: target-neutral, so a possibly-obsolete version is never named in the headline.
  missingFixStale: (version, count, severity) => ({
    headline: 'Security update needed.',
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? `, including a ${severity}-severity one` : ''}. A patched release is available.`,
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Known vulnerability with no fix yet.',
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? ` (up to ${severity}-severity)` : ''} with no released fix for the ${major} line yet. ` +
      'Apply the official mitigations and watch for the next release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Free security support for TYPO3 ${major} has ended.`,
    detail: 'Newer security fixes ship in ELTS releases only. ' +
      `${gated > 0 ? `This version is exposed to ${gated} ${plural(gated, 'issue', 'issues')} fixed only in ELTS releases. ` : ''}` +
      'Further patches require an ELTS subscription or an upgrade to a newer major.',
  }),
  eol: (major, eltsUntilIso) => ({
    headline: `TYPO3 ${major} is end of life.`,
    detail: `Security support ended on ${fmtDate(eltsUntilIso, 'en')}. No further fixes are published, ` +
      'even with ELTS. An upgrade to a supported version is required.',
  }),
  soon: (major, endsIso, hasElts) => ({
    headline: 'Plan an upgrade soon.',
    detail: `${hasElts ? 'ELTS' : 'Free'} security support for TYPO3 ${major} ends on ${fmtDate(endsIso, 'en')}.`,
  }),
  reviewOptional: (count) => ({
    headline: 'No core issues. Check the optional extensions.',
    detail: `${count} ${plural(count, 'advisory', 'advisories')} may apply depending on which extensions are installed.`,
  }),
  behind: (version, target) => ({
    headline: 'Secure, but a newer release is available.',
    detail: `Updating ${version} → ${target} is low-risk and recommended.`,
  }),
  allGood: (hasElts) => ({
    headline: 'Up to date.',
    detail: `This is the latest ${hasElts ? '' : 'free '}release of a supported version. Nothing to do right now.`,
  }),
  stale: (updatedIso) => ({
    headline: 'This result is unconfirmed.',
    detail: `Our security data was last verified on ${fmtDate(updatedIso, 'en')} and may be out of date. ` +
      'Treat a clean result with caution until it refreshes.',
  }),
  unknownVersion: () => ({
    headline: 'Unrecognised version.',
    detail: 'Enter the exact TYPO3 version, for example 12.4.10.',
  }),
  unknownMajor: (major, maintainedIso, eltsIso) => ({
    headline: `Enter the full version, e.g. ${major}.4.10`,
    detail: `TYPO3 ${major} is supported until ${fmtDate(maintainedIso, 'en')} (free) / ` +
      `${fmtDate(eltsIso, 'en')} (ELTS). For the security check we need the exact patch version.`,
  }),
  concernOptional: (count, packages) =>
    `${count} ${plural(count, 'advisory', 'advisories')} may also apply depending on installed extensions (${packages.join(', ')}).`,
  concernSeparateUnfixed: () => "A separate known issue has no released fix yet, so updating won't resolve it.",
  concernStale: (updatedIso) =>
    `Based on security data from ${fmtDate(updatedIso, 'en')}, which may be out of date. Confirm the current release before updating.`,
  concernMaybeNewer: (updatedIso) =>
    `Our release data was last verified on ${fmtDate(updatedIso, 'en')}, so this version may be newer than our data.`,
  concernEltsGatedRemain: (count) =>
    `${count} core ${plural(count, 'issue is', 'issues are')} fixed only in ELTS releases. A free update won't resolve ` +
    `${plural(count, 'it', 'them')}; that needs an ELTS subscription or a newer major.`,
  concernAlsoBehind: (count) => `This version is also ${count} free ${plural(count, 'release', 'releases')} behind on this line.`,
  concernNewerMajor: (major) => `TYPO3 ${major} is available as a newer major version.`,
  // The data's severity values are already plain English; only "unknown" needs saying properly.
  severityLabel: (severity) => (severity === 'unknown' ? 'not rated' : severity),
  showAllAdvisories: (total) => `Show all ${total}`,
  ui: {
    title: 'Is your TYPO3 up to date?',
    tagline: 'Known vulnerabilities and support status for any TYPO3 version.',
    versionLabel: 'Which TYPO3 version is your site running?',
    versionHint: 'The exact version is in the backend top bar, or run composer show typo3/cms-core.',
    majorLabel: 'Major version',
    yourVersion: 'Installed version',
    tagLatest: 'latest',
    tagSecurity: 'security release',
    tagElts: 'ELTS',
    eltsLabel: 'This site has an ELTS subscription',
    check: 'Check',
    affects: 'Affects this version',
    mayApply: 'May apply, depending on installed extensions',
    copyLink: 'Copy link to this result',
    copied: 'Link copied',
    shareHint: 'The link carries the version and ELTS setting, so it reopens this exact result.',
    officialAdvisory: 'Official advisory',
    opensNewTab: 'opens in a new tab',
    onlyIfUses: 'Only relevant if the site uses',
    loadError: 'Could not load the update data. Try reloading.',
    langNavLabel: 'Language',
    resultLabel: 'Result',
    resourcesLabel: 'Resources',
    madeBy: 'Made with ♥ by',
  },
};

const DE: Strings = {
  tierLabel: {
    'critical-missing-fix': 'Kritisch', 'critical-unfixed': 'Kritisch',
    'critical-elts-only': 'Kritisch', 'critical-eol': 'Kritisch',
    'soon-support-ending': 'Bald aktualisieren', 'review-optional': 'Prüfen',
    'behind-maintenance': 'Update empfohlen', 'all-good': 'Alles aktuell',
    'stale-data': 'Unbestätigt', 'unknown-version': 'Version prüfen',
  },
  missingFix: (version, target, count, severity, hasElts) => ({
    headline: `Jetzt auf TYPO3 ${target} aktualisieren.`,
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? `, darunter eine mit Schweregrad „${severity}“` : ''}. ` +
      `${hasElts ? 'Ein gepatchtes Release ist verfügbar.' : 'Ein kostenloses gepatchtes Release ist verfügbar.'}`,
  }),
  // Stale-Variante: ohne konkrete Zielversion, damit kein möglicherweise veraltetes Release genannt wird.
  missingFixStale: (version, count, severity) => ({
    headline: 'Sicherheitsupdate erforderlich.',
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? `, darunter eine mit Schweregrad „${severity}“` : ''}. Ein gepatchtes Release ist verfügbar.`,
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Bekannte Sicherheitslücke, noch ohne Fix.',
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? ` (bis Schweregrad „${severity}“)` : ''} und es gibt noch kein Release für die ${major}er-Linie. ` +
      'Wenden Sie die offiziellen Gegenmaßnahmen an und warten Sie auf das nächste Release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Der kostenlose Sicherheitssupport für TYPO3 ${major} ist beendet.`,
    detail: 'Neuere Sicherheitsfixes erscheinen nur noch in ELTS-Releases. ' +
      `${gated > 0 ? `Diese Version ist ${plural(gated, 'einem Sicherheitsproblem ausgesetzt, das nur in ELTS-Releases behoben ist', `${gated} Sicherheitsproblemen ausgesetzt, die nur in ELTS-Releases behoben sind`)}. ` : ''}` +
      'Weitere Patches erfordern ein ELTS-Abo oder ein Upgrade auf eine neuere Major-Version.',
  }),
  eol: (major, eltsUntilIso) => ({
    headline: `TYPO3 ${major} hat das Lebensende erreicht.`,
    detail: `Der Sicherheitssupport endete am ${fmtDate(eltsUntilIso, 'de')}. Es werden keine Fixes mehr ` +
      'veröffentlicht, auch nicht über ELTS. Ein Upgrade auf eine unterstützte Version ist erforderlich.',
  }),
  soon: (major, endsIso, hasElts) => ({
    headline: 'Bald ein Upgrade einplanen.',
    detail: `Der ${hasElts ? 'ELTS-' : 'kostenlose '}Sicherheitssupport für TYPO3 ${major} endet am ${fmtDate(endsIso, 'de')}.`,
  }),
  reviewOptional: (count) => ({
    headline: 'Keine Kernprobleme. Prüfen Sie die optionalen Erweiterungen.',
    detail: `${count} ${plural(count, 'Hinweis betrifft', 'Hinweise betreffen')} eventuell installierte Erweiterungen.`,
  }),
  behind: (version, target) => ({
    headline: 'Sicher, aber ein neueres Release ist verfügbar.',
    detail: `Das Update ${version} → ${target} ist risikoarm und empfohlen.`,
  }),
  allGood: (hasElts) => ({
    headline: 'Aktueller Stand.',
    detail: `Das ist das aktuellste ${hasElts ? '' : 'kostenlose '}Release einer unterstützten Version. Nichts zu tun.`,
  }),
  stale: (updatedIso) => ({
    headline: 'Dieses Ergebnis ist unbestätigt.',
    detail: `Unsere Sicherheitsdaten wurden zuletzt am ${fmtDate(updatedIso, 'de')} geprüft und sind möglicherweise nicht mehr aktuell. ` +
      'Behandeln Sie ein „sauberes“ Ergebnis bis zur nächsten Aktualisierung mit Vorsicht.',
  }),
  unknownVersion: () => ({
    headline: 'Unbekannte Version.',
    detail: 'Geben Sie die genaue TYPO3-Version ein, zum Beispiel 12.4.10.',
  }),
  unknownMajor: (major, maintainedIso, eltsIso) => ({
    headline: `Bitte die vollständige Version angeben, z. B. ${major}.4.10`,
    detail: `TYPO3 ${major} wird unterstützt bis ${fmtDate(maintainedIso, 'de')} (kostenlos) / ` +
      `${fmtDate(eltsIso, 'de')} (ELTS). Für die Sicherheitsprüfung brauchen wir die genaue Patch-Version.`,
  }),
  concernOptional: (count, packages) =>
    `${count} ${plural(count, 'Hinweis betrifft', 'Hinweise betreffen')} eventuell installierte Erweiterungen (${packages.join(', ')}).`,
  concernSeparateUnfixed: () => 'Ein weiteres bekanntes Problem hat noch keinen Fix, das Update behebt es also nicht.',
  concernStale: (updatedIso) =>
    `Basiert auf Sicherheitsdaten vom ${fmtDate(updatedIso, 'de')} und ist möglicherweise nicht mehr aktuell. Prüfen Sie vor dem Update das aktuelle Release.`,
  concernMaybeNewer: (updatedIso) =>
    `Unsere Release-Daten wurden zuletzt am ${fmtDate(updatedIso, 'de')} geprüft, diese Version könnte also neuer sein als unsere Daten.`,
  concernEltsGatedRemain: (count) =>
    `${count} ${plural(count, 'Kernproblem ist', 'Kernprobleme sind')} nur in ELTS-Releases behoben. Ein kostenloses Update beseitigt ` +
    `${plural(count, 'es', 'sie')} nicht; dafür braucht es ein ELTS-Abo oder eine neuere Major-Version.`,
  concernAlsoBehind: (count) => `Diese Version ist außerdem ${count} ${plural(count, 'kostenloses Release', 'kostenlose Releases')} im Rückstand.`,
  concernNewerMajor: (major) => `TYPO3 ${major} ist als neuere Major-Version verfügbar.`,
  severityLabel: (severity) =>
    ({ critical: 'kritisch', high: 'hoch', medium: 'mittel', low: 'niedrig', unknown: 'nicht eingestuft' })[severity] ?? severity,
  showAllAdvisories: (total) => `Alle ${total} anzeigen`,
  ui: {
    title: 'Ist Ihre TYPO3-Installation aktuell?',
    tagline: 'Bekannte Sicherheitslücken und Support-Status für jede TYPO3-Version.',
    versionLabel: 'Welche TYPO3-Version läuft auf Ihrer Website?',
    versionHint: 'Die genaue Version steht in der Backend-Kopfzeile oder liefert composer show typo3/cms-core.',
    majorLabel: 'Major-Version',
    yourVersion: 'Installierte Version',
    tagLatest: 'neueste',
    tagSecurity: 'Sicherheitsrelease',
    tagElts: 'ELTS',
    eltsLabel: 'Diese Website hat ein ELTS-Abo',
    check: 'Prüfen',
    affects: 'Betrifft diese Version',
    mayApply: 'Kann zutreffen, je nach installierten Erweiterungen',
    copyLink: 'Link zu diesem Ergebnis kopieren',
    copied: 'Link kopiert',
    shareHint: 'Der Link enthält Version und ELTS-Einstellung und öffnet genau dieses Ergebnis wieder.',
    officialAdvisory: 'Offizieller Hinweis',
    opensNewTab: 'öffnet in einem neuen Tab',
    onlyIfUses: 'Nur relevant, wenn die Website nutzt:',
    loadError: 'Die Update-Daten konnten nicht geladen werden. Bitte neu laden.',
    langNavLabel: 'Sprache',
    resultLabel: 'Ergebnis',
    resourcesLabel: 'Ressourcen',
    madeBy: 'Mit ♥ gemacht von',
  },
};

export function strings(lang: Lang): Strings {
  return lang === 'de' ? DE : EN;
}
