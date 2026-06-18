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
  notSureSummary: string;
  notSureBody: string;
  eltsLabel: string;
  check: string;
  affects: string;
  mayApply: string;
  copyLink: string;
  copied: string;
  shareHint: string;
  officialAdvisory: string;
  onlyIfUses: string;
  loadError: string;
}

export interface Strings {
  tierLabel: Record<Tier, string>;
  missingFix(version: string, target: string, count: number, severity: string | null, hasElts: boolean): TierText;
  unfixed(version: string, major: string, count: number, severity: string | null): TierText;
  eltsOnly(major: string, gated: number): TierText;
  eol(major: string, eltsUntilIso: string): TierText;
  soon(major: string, endsIso: string, hasElts: boolean): TierText;
  reviewOptional(count: number): TierText;
  behind(version: string, target: string): TierText;
  allGood(hasElts: boolean): TierText;
  unknownVersion(): TierText;
  unknownMajor(major: string, maintainedIso: string, eltsIso: string): TierText;
  concernOptional(count: number, packages: string[]): string;
  concernSeparateUnfixed(): string;
  concernAlsoBehind(count: number): string;
  concernNewerMajor(major: number): string;
  ui: UiLabels;
}

const EN: Strings = {
  tierLabel: {
    'critical-missing-fix': 'Critical', 'critical-unfixed': 'Critical',
    'critical-elts-only': 'Critical', 'critical-eol': 'Critical',
    'soon-support-ending': 'Update soon', 'review-optional': 'Review',
    'behind-maintenance': 'Update advised', 'all-good': 'All good',
    'unknown-version': 'Check the version',
  },
  missingFix: (version, target, count, severity, hasElts) => ({
    headline: `Update to TYPO3 ${target} now.`,
    detail: `${count} known ${plural(count, 'vulnerability', 'vulnerabilities')} affect ${version}` +
      `${severity ? `, including a ${severity}-severity one` : ''}. ` +
      `${hasElts ? 'A patched release is available.' : 'A free patched release is available.'}`,
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Known vulnerability with no fix yet.',
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? ` (up to ${severity}-severity)` : ''} with no released fix for the ${major} line yet. ` +
      'Apply the official mitigations and watch for the next release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Free security support for TYPO3 ${major} has ended.`,
    detail: 'Newer security fixes are ELTS-only. ' +
      `${gated > 0 ? `Your version is exposed to ${gated} ${plural(gated, 'issue', 'issues')} fixed only in ELTS releases. ` : ''}` +
      'To keep getting security patches you need an ELTS subscription or an upgrade to a newer TYPO3 version.',
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
    headline: 'Your core looks fine — check optional extensions.',
    detail: `${count} ${plural(count, 'advisory', 'advisories')} may apply depending on which extensions are installed.`,
  }),
  behind: (version, target) => ({
    headline: "You're secure, but a newer release is available.",
    detail: `Updating ${version} → ${target} is low-risk and recommended.`,
  }),
  allGood: (hasElts) => ({
    headline: "You're up to date.",
    detail: `You're on the latest ${hasElts ? '' : 'free '}release of a supported version. Nothing to do right now.`,
  }),
  unknownVersion: () => ({
    headline: "We couldn't recognise that version.",
    detail: 'Enter the exact TYPO3 version, for example 12.4.10.',
  }),
  unknownMajor: (major, maintainedIso, eltsIso) => ({
    headline: `Enter the full version, e.g. ${major}.4.10`,
    detail: `TYPO3 ${major} is supported until ${fmtDate(maintainedIso, 'en')} (free) / ` +
      `${fmtDate(eltsIso, 'en')} (ELTS). For the security check we need the exact patch version.`,
  }),
  concernOptional: (count, packages) =>
    `${count} ${plural(count, 'advisory', 'advisories')} may also apply depending on installed extensions (${packages.join(', ')}).`,
  concernSeparateUnfixed: () => "A separate known issue has no released fix yet — updating won't resolve it.",
  concernAlsoBehind: (count) => `You're also ${count} free ${plural(count, 'release', 'releases')} behind on this line.`,
  concernNewerMajor: (major) => `TYPO3 ${major} is available as a newer major version.`,
  ui: {
    title: 'Is your TYPO3 up to date?',
    tagline: "Check any client's TYPO3 and share the result.",
    versionLabel: 'Which TYPO3 version is the site on?',
    versionHint: "You'll find the exact version in the TYPO3 backend top bar.",
    majorLabel: 'TYPO3 version line',
    yourVersion: 'Your version',
    tagLatest: 'latest',
    tagSecurity: 'security release',
    tagElts: 'ELTS',
    notSureSummary: 'Not sure which version?',
    notSureBody: 'Log in to the TYPO3 backend — the exact version is in the top bar and on the About / Maintenance screen.',
    eltsLabel: 'This site has an ELTS subscription',
    check: 'Check',
    affects: 'Affects this site',
    mayApply: 'May apply, depending on installed extensions',
    copyLink: 'Copy link to this result',
    copied: 'Link copied',
    shareHint: 'Send it to your client, or schedule your team to perform the update.',
    officialAdvisory: 'Official advisory',
    onlyIfUses: 'Only relevant if the site uses',
    loadError: 'Sorry — could not load update data.',
  },
};

const DE: Strings = {
  tierLabel: {
    'critical-missing-fix': 'Kritisch', 'critical-unfixed': 'Kritisch',
    'critical-elts-only': 'Kritisch', 'critical-eol': 'Kritisch',
    'soon-support-ending': 'Bald aktualisieren', 'review-optional': 'Prüfen',
    'behind-maintenance': 'Update empfohlen', 'all-good': 'Alles aktuell',
    'unknown-version': 'Version prüfen',
  },
  missingFix: (version, target, count, severity, hasElts) => ({
    headline: `Jetzt auf TYPO3 ${target} aktualisieren.`,
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? `, darunter eine mit Schweregrad „${severity}“` : ''}. ` +
      `${hasElts ? 'Ein gepatchtes Release ist verfügbar.' : 'Ein kostenloses gepatchtes Release ist verfügbar.'}`,
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Bekannte Sicherheitslücke, noch ohne Fix.',
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? ` (bis Schweregrad „${severity}“)` : ''} und es gibt noch kein Release für die ${major}er-Linie. ` +
      'Wenden Sie die offiziellen Gegenmaßnahmen an und warten Sie auf das nächste Release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Der kostenlose Sicherheitssupport für TYPO3 ${major} ist beendet.`,
    detail: 'Neuere Sicherheitsfixes gibt es nur über ELTS. ' +
      `${gated > 0 ? `Ihre Version ist ${gated} ${plural(gated, 'Problem', 'Problemen')} ausgesetzt, die nur in ELTS-Releases behoben sind. ` : ''}` +
      'Für weitere Sicherheitsupdates brauchen Sie ein ELTS-Abo oder ein Upgrade auf eine neuere TYPO3-Version.',
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
    headline: 'Der Kern ist in Ordnung — prüfen Sie optionale Erweiterungen.',
    detail: `${count} ${plural(count, 'Hinweis betrifft', 'Hinweise betreffen')} eventuell installierte Erweiterungen.`,
  }),
  behind: (version, target) => ({
    headline: 'Sicher, aber ein neueres Release ist verfügbar.',
    detail: `Das Update ${version} → ${target} ist risikoarm und empfohlen.`,
  }),
  allGood: (hasElts) => ({
    headline: 'Sie sind auf dem aktuellen Stand.',
    detail: `Sie nutzen das aktuellste ${hasElts ? '' : 'kostenlose '}Release einer unterstützten Version. Nichts zu tun.`,
  }),
  unknownVersion: () => ({
    headline: 'Diese Version konnten wir nicht erkennen.',
    detail: 'Geben Sie die genaue TYPO3-Version ein, zum Beispiel 12.4.10.',
  }),
  unknownMajor: (major, maintainedIso, eltsIso) => ({
    headline: `Bitte die vollständige Version angeben, z. B. ${major}.4.10`,
    detail: `TYPO3 ${major} wird unterstützt bis ${fmtDate(maintainedIso, 'de')} (kostenlos) / ` +
      `${fmtDate(eltsIso, 'de')} (ELTS). Für die Sicherheitsprüfung brauchen wir die genaue Patch-Version.`,
  }),
  concernOptional: (count, packages) =>
    `${count} ${plural(count, 'Hinweis betrifft', 'Hinweise betreffen')} eventuell installierte Erweiterungen (${packages.join(', ')}).`,
  concernSeparateUnfixed: () => 'Ein weiteres bekanntes Problem hat noch keinen Fix — das Update behebt es nicht.',
  concernAlsoBehind: (count) => `Sie sind außerdem ${count} kostenlose ${plural(count, 'Release', 'Releases')} im Rückstand.`,
  concernNewerMajor: (major) => `TYPO3 ${major} ist als neuere Hauptversion verfügbar.`,
  ui: {
    title: 'Ist Ihr TYPO3 aktuell?',
    tagline: 'Prüfen Sie das TYPO3 eines Kunden und teilen Sie das Ergebnis.',
    versionLabel: 'Welche TYPO3-Version läuft auf der Website?',
    versionHint: 'Die genaue Version steht in der TYPO3-Backend-Kopfzeile.',
    majorLabel: 'TYPO3-Versionslinie',
    yourVersion: 'Ihre Version',
    tagLatest: 'neueste',
    tagSecurity: 'Sicherheitsrelease',
    tagElts: 'ELTS',
    notSureSummary: 'Version unbekannt?',
    notSureBody: 'Melden Sie sich im TYPO3-Backend an — die genaue Version steht in der Kopfzeile und im Bereich Über / Wartung.',
    eltsLabel: 'Diese Website hat ein ELTS-Abo',
    check: 'Prüfen',
    affects: 'Betrifft diese Website',
    mayApply: 'Kann zutreffen, je nach installierten Erweiterungen',
    copyLink: 'Link zu diesem Ergebnis kopieren',
    copied: 'Link kopiert',
    shareHint: 'Senden Sie ihn an Ihren Kunden oder planen Sie das Update mit Ihrem Team.',
    officialAdvisory: 'Offizieller Hinweis',
    onlyIfUses: 'Nur relevant, wenn die Website nutzt:',
    loadError: 'Entschuldigung — die Update-Daten konnten nicht geladen werden.',
  },
};

export function strings(lang: Lang): Strings {
  return lang === 'de' ? DE : EN;
}
