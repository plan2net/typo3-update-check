import type { Lang, Tier } from './types';
import { fmtDate, plural } from './format';

interface TierText {
  headline: string;
  detail: string;
}

export interface UiLabels {
  title: string;
  brand: string;
  heroTitle: string;
  tagline: string;
  pickLabel: string;
  majorLabel: string;
  yourVersion: string;
  tagLatest: string;
  tagSecurity: string;
  tagThirdParty: string;
  tagElts: string;
  eltsShort: string;
  affects: string;
  mayApply: string;
  bundledHeading: string;
  openAdvisories: string;
  today: string;
  zoneFree: string;
  zoneElts: string;
  devHintLead: string;
  devHintTail: string;
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
  imprint: string;
  aiDisclaimer: string;
}

export interface TimelineAltInfo {
  major: string;
  firstIso: string;
  maintainedIso: string;
  eltsIso: string;
  version: string;
  releaseIso: string;
  ageMonths: number;
}

export interface BundledInfo {
  count: number;
  packages: string[];
  target: string;
  fixed: number;                 // fixed by updating to target
  composerUpdateNeeded: boolean; // some of those fixes are not forced on Composer installs by target
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
  behindLibraryFixes(version: string, target: string): TierText;
  allGood(free: boolean): TierText;
  stale(updatedIso: string): TierText;
  unknownVersion(): TierText;
  unknownMajor(major: string, maintainedIso: string, eltsIso: string): TierText;
  concernOptional(count: number, packages: string[]): string;
  concernBundled(info: BundledInfo): string;
  concernEltsLibrariesUnknown(): string;
  bundledCaveat(packageName: string, fixVersion: string | null, composerGuaranteed: boolean): string;
  concernSeparateUnfixed(): string;
  concernStale(updatedIso: string): string;
  concernMaybeNewer(updatedIso: string): string;
  concernEltsGatedRemain(count: number): string;
  concernAlsoBehind(count: number): string;
  concernNewerMajor(major: number): string;
  severityLabel(severity: string): string;
  showRemaining(count: number): string;
  nothingAffects(version: string): string;
  splitBoth(free: number, eltsOnly: number): string;
  splitEltsOnly(count: number): string;
  splitAllFree(count: number): string;
  lifecycleTitle(major: string): string;
  lifecycleEnded(monthsAgo: number, eltsIso: string): string;
  lifecycleRunning(monthsLeft: number, eltsIso: string): string;
  eltsNotYet(major: string, maintainedIso: string): string;
  releasedFlag(version: string, ageMonths: number): string;
  timelineAlt(info: TimelineAltInfo): string;
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
      `${severity ? plural(count, `, rated ${EN.severityLabel(severity)}`, `, including a ${EN.severityLabel(severity)}-severity one`) : ''}. ` +
      `${hasElts ? 'A patched release is available.' : 'A free patched release is available.'}`,
  }),
  // Stale variant: target-neutral, so a possibly-obsolete version is never named in the headline.
  missingFixStale: (version, count, severity) => ({
    headline: 'Security update needed.',
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? plural(count, `, rated ${EN.severityLabel(severity)}`, `, including a ${EN.severityLabel(severity)}-severity one`) : ''}. ` +
      'A patched release is available.',
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Known vulnerability with no fix yet.',
    detail: `${count} known ${plural(count, 'vulnerability affects', 'vulnerabilities affect')} ${version}` +
      `${severity ? plural(count, ` (rated ${EN.severityLabel(severity)})`, ` (up to ${EN.severityLabel(severity)} severity)`) : ''} with no released fix for the ${major} line yet. ` +
      'Apply the official mitigations and watch for the next release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Free security support for TYPO3 ${major} has ended.`,
    detail: 'Newer security fixes ship in ELTS releases only. ' +
      `${gated > 0 ? `This version is exposed to ${gated} ${plural(gated, 'issue', 'issues')} fixed only in ELTS releases. ` : ''}` +
      'Further patches require an ELTS subscription or an upgrade to a newer major.',
  }),
  eol: (major, eltsUntilIso) => ({
    headline: `TYPO3 ${major} has reached end of life.`,
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
  behindLibraryFixes: (version, target) => ({
    headline: 'A newer release fixes security issues in shipped libraries.',
    detail: `TYPO3 itself has no known issue here. Updating ${version} → ${target} is low-risk and recommended.`,
  }),
  allGood: (free) => ({
    headline: 'Up to date.',
    detail: `This is the latest ${free ? 'free ' : ''}release of a supported version. Nothing to do right now.`,
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
  concernBundled: ({ count, packages, target, fixed, composerUpdateNeeded }) => {
    const unfixed = count - fixed;
    return `${count} known security ${plural(count, 'issue', 'issues')} in ${plural(packages.length, 'a library', 'libraries')} ` +
      `shipped with this TYPO3 version (${packages.slice(0, 3).join(', ')}). ` +
      (unfixed === 0
        ? `Updating to TYPO3 ${target} fixes ${plural(count, 'it', 'them')}.`
        : fixed > 0
          ? `Updating to TYPO3 ${target} fixes ${fixed}; ${unfixed} ${plural(unfixed, 'has', 'have')} no fix in a free TYPO3 release yet.`
          : `No free TYPO3 update fixes ${plural(count, 'it', 'them')} yet.`) +
      (fixed > 0 && composerUpdateNeeded ? ' With Composer, also run composer update so the libraries are updated too.' : '') +
      (unfixed > 0 ? ' With Composer, composer audit shows whether you can install a fixed library version yourself.' : '');
  },
  concernEltsLibrariesUnknown: () =>
    "The libraries shipped with ELTS releases are not public, so we can't check them. With Composer, composer audit shows your actual state.",
  bundledCaveat: (packageName, fixVersion, composerGuaranteed) =>
    `Library ${packageName}. ` +
    (fixVersion
      ? `Fixed in TYPO3 ${fixVersion}.${composerGuaranteed ? '' : ' With Composer, also run composer update.'}`
      : 'No free TYPO3 release fixes it yet. With Composer, composer audit shows whether a fixed version can be installed.') +
    " We don't check whether TYPO3 actually uses the affected code.",
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
  showRemaining: (count) => `Show the remaining ${count} ${plural(count, 'advisory', 'advisories')}`,
  nothingAffects: (version) => `Nothing published affects ${version} right now.`,
  splitBoth: (free, eltsOnly) =>
    `A free update fixes ${free} of these. The other ${eltsOnly} ${plural(eltsOnly, 'is', 'are')} patched only in ELTS releases.`,
  splitEltsOnly: (count) =>
    `${plural(count, 'It is', `All ${count} are`)} patched only in ELTS releases. A free update will not fix ${plural(count, 'it', 'them')}.`,
  splitAllFree: (count) => `A free update fixes ${plural(count, 'it', 'all of them')}.`,
  lifecycleTitle: (major) => `Where TYPO3 ${major} stands`,
  lifecycleEnded: (monthsAgo, eltsIso) =>
    `Free security updates ended ${monthsAgo} ${plural(monthsAgo, 'month', 'months')} ago. ` +
    `Paid ELTS runs to ${fmtDate(eltsIso, 'en')}.`,
  lifecycleRunning: (monthsLeft, eltsIso) =>
    `Free security updates run for another ${monthsLeft} ${plural(monthsLeft, 'month', 'months')}, ` +
    `then paid ELTS to ${fmtDate(eltsIso, 'en')}.`,
  eltsNotYet: (major, maintainedIso) =>
    `TYPO3 ${major} is actively maintained until ${fmtDate(maintainedIso, 'en')}. ELTS is not available yet.`,
  releasedFlag: (version, ageMonths) =>
    `${version} released · ${ageMonths} ${plural(ageMonths, 'month', 'months')} old`,
  timelineAlt: (info) =>
    `Support timeline for TYPO3 ${info.major}. Free security updates from ${fmtDate(info.firstIso, 'en')} ` +
    `to ${fmtDate(info.maintainedIso, 'en')}, then paid ELTS to ${fmtDate(info.eltsIso, 'en')}. ` +
    `Your release ${info.version} came out ${fmtDate(info.releaseIso, 'en')}, ${info.ageMonths} ` +
    `${plural(info.ageMonths, 'month', 'months')} ago.`,
  ui: {
    title: 'Is your TYPO3 up to date?',
    brand: 'TYPO3 Update Check',
    heroTitle: 'Is your TYPO3 safe?',
    tagline: 'Pick the version your site runs. We check it against every published TYPO3 security advisory and tell you whether a free update fixes it.',
    pickLabel: 'My site runs',
    majorLabel: 'TYPO3 major version',
    yourVersion: 'Installed version',
    tagLatest: 'latest free release',
    tagSecurity: 'security release',
    tagThirdParty: '3rd-party security fixes',
    tagElts: 'ELTS',
    eltsShort: 'We have ELTS',
    affects: 'Affects your site',
    mayApply: 'Only if you use these extensions',
    bundledHeading: 'Libraries shipped with TYPO3',
    openAdvisories: 'Open advisories',
    today: 'Today',
    zoneFree: 'Free security updates',
    zoneElts: 'ELTS only, paid',
    devHintLead: 'Developers: this check also runs inside Composer.',
    devHintTail: 'It stops a TYPO3 core update that carries security fixes or breaking changes and asks before continuing.',
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
    imprint: 'Imprint',
    aiDisclaimer:
      'The advisory explanations are generated with AI. We assume no responsibility for these texts; the official advisory is authoritative.',
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
      `${severity ? plural(count, `, Schweregrad „${DE.severityLabel(severity)}“`, `, darunter eine mit Schweregrad „${DE.severityLabel(severity)}“`) : ''}. ` +
      `${hasElts ? 'Ein Update ist verfügbar.' : 'Ein kostenloses Update ist verfügbar.'}`,
  }),
  // Stale-Variante: ohne konkrete Zielversion, damit kein möglicherweise veraltetes Release genannt wird.
  missingFixStale: (version, count, severity) => ({
    headline: 'Sicherheitsupdate erforderlich.',
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? plural(count, `, Schweregrad „${DE.severityLabel(severity)}“`, `, darunter eine mit Schweregrad „${DE.severityLabel(severity)}“`) : ''}. ` +
      'Ein Update ist verfügbar.',
  }),
  unfixed: (version, major, count, severity) => ({
    headline: 'Bekannte Sicherheitslücke, noch ohne Fix.',
    detail: `${count} bekannte ${plural(count, 'Sicherheitslücke betrifft', 'Sicherheitslücken betreffen')} ${version}` +
      `${severity ? plural(count, ` (Schweregrad „${DE.severityLabel(severity)}“)`, ` (bis Schweregrad „${DE.severityLabel(severity)}“)`) : ''}. ` +
      `Für TYPO3 ${major} gibt es noch kein Release mit Fix. ` +
      'Wenden Sie die offiziellen Gegenmaßnahmen an und warten Sie auf das nächste Release.',
  }),
  eltsOnly: (major, gated) => ({
    headline: `Der kostenlose Sicherheitssupport für TYPO3 ${major} ist beendet.`,
    detail: 'Neuere Sicherheitsfixes erscheinen nur noch in ELTS-Releases. ' +
      `${gated > 0 ? `Diese Version ist ${plural(gated, 'einem Sicherheitsproblem ausgesetzt, das nur in ELTS-Releases behoben ist', `${gated} Sicherheitsproblemen ausgesetzt, die nur in ELTS-Releases behoben sind`)}. ` : ''}` +
      'Weitere Patches erfordern ein ELTS-Abo oder ein Upgrade auf eine neuere Major-Version.',
  }),
  eol: (major, eltsUntilIso) => ({
    headline: `TYPO3 ${major} wird nicht mehr unterstützt.`,
    detail: `Der Sicherheitssupport endete am ${fmtDate(eltsUntilIso, 'de')}. Es werden keine Fixes mehr ` +
      'veröffentlicht, auch nicht über ELTS. Ein Upgrade auf eine unterstützte Version ist erforderlich.',
  }),
  soon: (major, endsIso, hasElts) => ({
    headline: 'Bald ein Upgrade einplanen.',
    detail: `Der ${hasElts ? 'ELTS-' : 'kostenlose '}Sicherheitssupport für TYPO3 ${major} endet am ${fmtDate(endsIso, 'de')}.`,
  }),
  reviewOptional: (count) => ({
    headline: 'Der Core ist nicht betroffen. Prüfen Sie die optionalen Erweiterungen.',
    detail: `${count} ${plural(count, 'Meldung kann', 'Meldungen können')} zutreffen, je nachdem, welche Erweiterungen installiert sind.`,
  }),
  behind: (version, target) => ({
    headline: 'Sicher, aber ein neueres Release ist verfügbar.',
    detail: `Das Update ${version} → ${target} ist risikoarm und empfohlen.`,
  }),
  behindLibraryFixes: (version, target) => ({
    headline: 'Ein neueres Release behebt Sicherheitslücken in mitgelieferten Bibliotheken.',
    detail: `TYPO3 selbst hat hier keine bekannte Lücke. Das Update ${version} → ${target} ist risikoarm und empfohlen.`,
  }),
  allGood: (free) => ({
    headline: 'Auf dem neuesten Stand.',
    detail: `Das ist das aktuellste ${free ? 'kostenlose ' : ''}Release einer unterstützten Version. Derzeit ist nichts zu tun.`,
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
    detail: `TYPO3 ${major} wird bis ${fmtDate(maintainedIso, 'de')} kostenlos und bis ` +
      `${fmtDate(eltsIso, 'de')} über ELTS unterstützt. Für die Sicherheitsprüfung brauchen wir die genaue Patch-Version.`,
  }),
  concernOptional: (count, packages) =>
    `${count} ${plural(count, 'Meldung kann', 'Meldungen können')} außerdem zutreffen, je nachdem, welche Erweiterungen installiert sind (${packages.join(', ')}).`,
  concernBundled: ({ count, packages, target, fixed, composerUpdateNeeded }) => {
    const unfixed = count - fixed;
    return `${count} bekannte ${plural(count, 'Sicherheitslücke', 'Sicherheitslücken')} in ` +
      `${plural(packages.length, 'einer mitgelieferten Bibliothek', 'mitgelieferten Bibliotheken')} (${packages.slice(0, 3).join(', ')}). ` +
      (unfixed === 0
        ? `Das Update auf TYPO3 ${target} behebt das.`
        : fixed > 0
          ? `Das Update auf TYPO3 ${target} behebt ${fixed}; ${unfixed} ${plural(unfixed, 'ist', 'sind')} noch in keinem kostenlosen TYPO3-Release behoben.`
          : 'Noch kein kostenloses TYPO3-Update behebt das.') +
      (fixed > 0 && composerUpdateNeeded ? ' Bei Composer zusätzlich composer update ausführen, damit auch die Bibliotheken aktualisiert werden.' : '') +
      (unfixed > 0 ? ' Bei Composer zeigt composer audit, ob Sie selbst eine behobene Version installieren können.' : '');
  },
  concernEltsLibrariesUnknown: () =>
    'Die mit ELTS-Releases ausgelieferten Bibliotheken sind nicht öffentlich einsehbar, daher können wir sie nicht prüfen. Bei Composer zeigt composer audit den tatsächlichen Stand.',
  bundledCaveat: (packageName, fixVersion, composerGuaranteed) =>
    `Bibliothek ${packageName}. ` +
    (fixVersion
      ? `Behoben in TYPO3 ${fixVersion}.${composerGuaranteed ? '' : ' Bei Composer zusätzlich composer update ausführen.'}`
      : 'Noch in keinem kostenlosen TYPO3-Release behoben. Bei Composer zeigt composer audit, ob eine behobene Version installierbar ist.') +
    ' Ob TYPO3 den betroffenen Code tatsächlich nutzt, prüfen wir nicht.',
  concernSeparateUnfixed: () => 'Ein weiteres bekanntes Problem hat noch keinen Fix, das Update behebt es also nicht.',
  concernStale: (updatedIso) =>
    `Basiert auf Sicherheitsdaten vom ${fmtDate(updatedIso, 'de')} und ist möglicherweise nicht mehr aktuell. Prüfen Sie vor dem Update das aktuelle Release.`,
  concernMaybeNewer: (updatedIso) =>
    `Unsere Release-Daten wurden zuletzt am ${fmtDate(updatedIso, 'de')} geprüft, diese Version könnte also neuer sein als unsere Daten.`,
  concernEltsGatedRemain: (count) =>
    `${count} ${plural(count, 'Sicherheitsproblem im Core ist', 'Sicherheitsprobleme im Core sind')} nur in ELTS-Releases behoben. Ein kostenloses Update beseitigt ` +
    `${plural(count, 'es', 'sie')} nicht; dafür braucht es ein ELTS-Abo oder eine neuere Major-Version.`,
  concernAlsoBehind: (count) => `Diese Version liegt außerdem ${count} ${plural(count, 'kostenloses Release', 'kostenlose Releases')} zurück.`,
  concernNewerMajor: (major) => `TYPO3 ${major} ist als neuere Major-Version verfügbar.`,
  severityLabel: (severity) =>
    ({ critical: 'kritisch', high: 'hoch', medium: 'mittel', low: 'niedrig', unknown: 'nicht eingestuft' })[severity] ?? severity,
  showRemaining: (count) =>
    `Die ${plural(count, 'restliche Meldung', `restlichen ${count} Meldungen`)} anzeigen`,
  nothingAffects: (version) => `Derzeit betrifft keine veröffentlichte Meldung ${version}.`,
  splitBoth: (free, eltsOnly) =>
    `Ein kostenloses Update behebt ${free} davon. Die ${plural(eltsOnly, 'andere ist', `anderen ${eltsOnly} sind`)} ` +
    'nur in ELTS-Releases behoben.',
  splitEltsOnly: (count) =>
    `${plural(count, 'Sie ist', `Alle ${count} sind`)} nur in ELTS-Releases behoben. Ein kostenloses Update behebt sie nicht.`,
  splitAllFree: (count) => `Ein kostenloses Update behebt ${plural(count, 'sie', 'alle')}.`,
  lifecycleTitle: (major) => `Supportstatus von TYPO3 ${major}`,
  lifecycleEnded: (monthsAgo, eltsIso) =>
    `Die kostenlosen Sicherheitsupdates endeten vor ${monthsAgo} ${plural(monthsAgo, 'Monat', 'Monaten')}. ` +
    `Kostenpflichtiges ELTS läuft bis ${fmtDate(eltsIso, 'de')}.`,
  lifecycleRunning: (monthsLeft, eltsIso) =>
    `Kostenlose Sicherheitsupdates laufen noch ${monthsLeft} ${plural(monthsLeft, 'Monat', 'Monate')}, ` +
    `danach kostenpflichtiges ELTS bis ${fmtDate(eltsIso, 'de')}.`,
  eltsNotYet: (major, maintainedIso) =>
    `TYPO3 ${major} wird bis ${fmtDate(maintainedIso, 'de')} aktiv gepflegt. ELTS ist noch nicht verfügbar.`,
  releasedFlag: (version, ageMonths) =>
    `${version} veröffentlicht ·${ageMonths} ${plural(ageMonths, 'Monat', 'Monate')} alt`,
  timelineAlt: (info) =>
    `Support-Zeitraum für TYPO3 ${info.major}. Kostenlose Sicherheitsupdates von ${fmtDate(info.firstIso, 'de')} ` +
    `bis ${fmtDate(info.maintainedIso, 'de')}, danach kostenpflichtiges ELTS bis ${fmtDate(info.eltsIso, 'de')}. ` +
    `Ihr Release ${info.version} erschien am ${fmtDate(info.releaseIso, 'de')}, vor ${info.ageMonths} ` +
    `${plural(info.ageMonths, 'Monat', 'Monaten')}.`,
  ui: {
    title: 'Ist Ihre TYPO3-Installation aktuell?',
    brand: 'TYPO3 Update Check',
    heroTitle: 'Ist Ihr TYPO3 sicher?',
    tagline: 'Wählen Sie die Version, die auf Ihrer Website läuft. Wir prüfen sie gegen alle veröffentlichten TYPO3-Sicherheitsmeldungen und sagen Ihnen, ob ein kostenloses Update genügt.',
    pickLabel: 'Meine Website nutzt',
    majorLabel: 'TYPO3-Major-Version',
    yourVersion: 'Installierte Version',
    tagLatest: 'neuestes kostenloses Release',
    tagSecurity: 'Sicherheitsrelease',
    tagThirdParty: 'Sicherheitsfixes in Drittanbieter-Bibliotheken',
    tagElts: 'ELTS',
    eltsShort: 'Wir haben ELTS',
    affects: 'Betrifft Ihre Website',
    mayApply: 'Nur bei diesen Erweiterungen',
    bundledHeading: 'Mit TYPO3 ausgelieferte Bibliotheken',
    openAdvisories: 'Offene Meldungen',
    today: 'Heute',
    zoneFree: 'Kostenlose Sicherheitsupdates',
    zoneElts: 'Nur ELTS, kostenpflichtig',
    devHintLead: 'Für Entwickler: Diese Prüfung läuft auch direkt in Composer.',
    devHintTail: 'Sie hält ein TYPO3-Core-Update mit Sicherheitsfixes oder Breaking Changes an und fragt vor dem Fortfahren nach.',
    copyLink: 'Link zu diesem Ergebnis kopieren',
    copied: 'Link kopiert',
    shareHint: 'Der Link enthält Version und ELTS-Einstellung und öffnet genau dieses Ergebnis wieder.',
    officialAdvisory: 'Offizielle Meldung',
    opensNewTab: 'öffnet in einem neuen Tab',
    onlyIfUses: 'Betrifft nur Websites mit',
    loadError: 'Die Update-Daten konnten nicht geladen werden. Bitte neu laden.',
    langNavLabel: 'Sprache',
    resultLabel: 'Ergebnis',
    resourcesLabel: 'Ressourcen',
    madeBy: 'Mit ♥ gemacht von',
    imprint: 'Impressum',
    aiDisclaimer:
      'Die Erläuterungen zu den Sicherheitsmeldungen werden mit KI erstellt. Für diese Texte übernehmen wir keine Gewähr; maßgeblich ist die offizielle Meldung.',
  },
};

export function strings(lang: Lang): Strings {
  return lang === 'de' ? DE : EN;
}
