import type { Typo3Data, Verdict, Tier, AffectingAdvisory, SupportPhase, Lang } from './types';
import { parseVersion, compareVersions } from './version';
import { topSeverity } from './format';
import { strings } from './i18n';

const SIX_MONTHS_MS = 1000 * 60 * 60 * 24 * 183;

export function computeVerdict(
  version: string,
  hasElts: boolean,
  data: Typo3Data,
  now: Date,
  lang: Lang = 'en',
): Verdict {
  const m = strings(lang);
  const base = (tier: Tier, t: { headline: string; detail: string }): Verdict => ({
    tier, supportPhase: 'unknown', recommendedVersion: null,
    headline: t.headline, detail: t.detail, affecting: [], concerns: [],
  });

  const parsed = parseVersion(version);
  if (!parsed) {
    return base('unknown-version', m.unknownVersion());
  }
  // Canonicalise once ("v12.4.10" / " 12.4.10 " -> "12.4.10") and use that everywhere below.
  const canonical = `${parsed[0]}.${parsed[1]}.${parsed[2]}`;
  const mk = String(parsed[0]);
  const major = data.majors[mk];
  if (!major) {
    return base('unknown-version', m.unknownVersion());
  }
  // Require a REAL released version. Otherwise a bogus "13.99.99" would fall through to
  // "all good" and falsely reassure. The release list includes ELTS releases, so any genuine
  // version is present (data is at most ~a day stale).
  if (!major.releases.some((r) => r.version === canonical)) {
    return base('unknown-version', m.unknownVersion());
  }

  const nowMs = now.getTime();
  const maintainedMs = new Date(major.maintainedUntil).getTime();
  const eltsMs = new Date(major.eltsUntil).getTime();
  const supportPhase: SupportPhase =
    nowMs < maintainedMs ? 'active' : nowMs < eltsMs ? 'elts-only' : 'eol';

  const target = hasElts ? major.latestElts : major.latestFree;
  const horizonMs = hasElts ? eltsMs : maintainedMs;

  const affecting: AffectingAdvisory[] = [];
  for (const a of data.advisories) {
    const e = a.affected[mk];
    if (!e) continue;
    if (compareVersions(canonical, e.from) < 0) continue;            // below the affected range
    if (e.fixedIn && compareVersions(canonical, e.fixedIn) >= 0) continue; // already patched
    affecting.push({
      advisory: a,
      fixVersion: e.fixedIn,
      fixIsFree: e.fixedIn ? !e.fixedInElts : false,
      optional: a.optional,
    });
  }

  // Only core (always-present) advisories drive the headline + count. Optional ones are
  // surfaced separately so the tool can't over-alarm about extensions that may not be installed.
  const core = affecting.filter((x) => !x.optional);
  const optional = affecting.filter((x) => x.optional);
  const coreReachableFix = core.filter(
    (x) => x.fixVersion !== null && (hasElts || x.fixIsFree) && compareVersions(target, x.fixVersion) >= 0,
  );
  const coreUnfixed = core.filter((x) => x.fixVersion === null);
  const coreEltsGatedFix = core.filter((x) => x.fixVersion !== null && !x.fixIsFree);
  const freeBehind = major.releases.filter((r) => !r.elts && compareVersions(r.version, canonical) > 0).length;

  let tier: Tier;
  let text: { headline: string; detail: string };
  let recommendedVersion: string | null = null;

  if (coreReachableFix.length > 0) {
    tier = 'critical-missing-fix';
    recommendedVersion = target;
    text = m.missingFix(canonical, target, coreReachableFix.length, topSeverity(coreReachableFix.map((x) => x.advisory.severity)), hasElts);
  } else if (supportPhase === 'eol') {
    tier = 'critical-eol';
    text = m.eol(mk, major.eltsUntil);
  } else if (coreUnfixed.length > 0) {
    tier = 'critical-unfixed';
    text = m.unfixed(canonical, mk, coreUnfixed.length, topSeverity(coreUnfixed.map((x) => x.advisory.severity)));
  } else if (!hasElts && (coreEltsGatedFix.length > 0 || supportPhase === 'elts-only')) {
    tier = 'critical-elts-only';
    text = m.eltsOnly(mk, coreEltsGatedFix.length);
  } else if (nowMs < horizonMs && horizonMs - nowMs < SIX_MONTHS_MS) {
    tier = 'soon-support-ending';
    recommendedVersion = target;
    text = m.soon(mk, hasElts ? major.eltsUntil : major.maintainedUntil, hasElts);
  } else if (optional.length > 0 && core.length === 0) {
    tier = 'review-optional';
    text = m.reviewOptional(optional.length);
  } else if (compareVersions(canonical, target) < 0) {
    tier = 'behind-maintenance';
    recommendedVersion = target;
    text = m.behind(canonical, target);
  } else {
    tier = 'all-good';
    text = m.allGood(hasElts);
  }

  // Secondary concerns the headline doesn't already cover.
  const concerns: string[] = [];
  if (optional.length > 0 && tier !== 'review-optional') {
    const pkgs = [...new Set(optional.map((x) => x.advisory.package))].slice(0, 3);
    concerns.push(m.concernOptional(optional.length, pkgs));
  }
  if (tier === 'critical-missing-fix' && coreUnfixed.length > 0) {
    concerns.push(m.concernSeparateUnfixed());
  }
  if (freeBehind > 0 && tier !== 'behind-maintenance' && tier !== 'critical-missing-fix' && tier !== 'all-good') {
    concerns.push(m.concernAlsoBehind(freeBehind));
  }
  if (data.majors[String(Number(mk) + 1)]) {
    concerns.push(m.concernNewerMajor(Number(mk) + 1));
  }

  return { tier, supportPhase, recommendedVersion, headline: text.headline, detail: text.detail, affecting, concerns };
}
