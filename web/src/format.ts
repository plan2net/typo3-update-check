import type { AffectingAdvisory, Lang, MajorInfo } from './types';

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];
const LOCALE: Record<Lang, string> = { en: 'en-GB', de: 'de-DE' };
const ADVISORY_ID = /^(TYPO3-CORE-SA-\d{4}-\d+)\s*:\s*(.+)$/;
const MS_PER_MONTH = 1000 * 60 * 60 * 24 * 30.44;

export function fmtDate(iso: string, lang: Lang = 'en'): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(LOCALE[lang], { day: 'numeric', month: 'short', year: 'numeric' });
}

export function plural(n: number, one: string, many: string): string {
  return n === 1 ? one : many;
}

/** Highest severity present, or null. */
export function topSeverity(severities: string[]): string | null {
  for (const s of SEVERITY_ORDER) {
    if (severities.includes(s)) return s;
  }
  return null;
}

/** Sort rank: 0 = most severe; unknown severities sort last. */
export function severityRank(severity: string): number {
  const rank = SEVERITY_ORDER.indexOf(severity);
  return rank === -1 ? SEVERITY_ORDER.length : rank;
}

export interface TitleParts {
  advisoryId: string | null;
  title: string;
}

/** Upstream titles arrive as "TYPO3-CORE-SA-2026-019: Broken Access Control…"; the id is metadata. */
export function splitAdvisoryTitle(title: string): TitleParts {
  const trimmed = title.trim();
  const match = trimmed.match(ADVISORY_ID);
  const advisoryId = match?.[1];
  const rest = match?.[2];
  if (advisoryId === undefined || rest === undefined) return { advisoryId: null, title: trimmed };
  return { advisoryId, title: rest.trim() };
}

export interface SeverityCount {
  severity: string;
  count: number;
}

export function severityCounts(severities: string[]): SeverityCount[] {
  const counts = new Map<string, number>();
  for (const severity of severities) counts.set(severity, (counts.get(severity) ?? 0) + 1);
  return [...counts.entries()]
    .map(([severity, count]) => ({ severity, count }))
    .sort((a, b) => severityRank(a.severity) - severityRank(b.severity));
}

export interface FixSplit {
  free: number;
  eltsOnly: number;
  unfixed: number;
}

/** The banner counts only the free-fixable ones, so showing the total alone reads as a mismatch. */
export function fixSplit(items: AffectingAdvisory[]): FixSplit {
  const split: FixSplit = { free: 0, eltsOnly: 0, unfixed: 0 };
  for (const item of items) {
    if (item.fixVersion === null) split.unfixed++;
    else if (item.fixIsFree) split.free++;
    else split.eltsOnly++;
  }
  return split;
}

export interface Timeline {
  freePercent: number;
  todayPercent: number;
  releasePercent: number;
  monthsToMaintained: number;
  releaseAgeMonths: number;
}

function timestamp(iso: string | null): number | null {
  if (iso === null) return null;
  const time = new Date(iso).getTime();
  return Number.isNaN(time) ? null : time;
}

function monthsBetween(from: number, to: number): number {
  return Math.round((to - from) / MS_PER_MONTH);
}

/**
 * The span runs from the major's earliest recorded release to the end of ELTS.
 * Null when the dataset cannot anchor it, so the caller omits the rail.
 */
export function supportTimeline(major: MajorInfo, releaseDateIso: string | null, now: Date): Timeline | null {
  const releaseDates = major.releases.map((release) => timestamp(release.date)).filter((time): time is number => time !== null);
  const start = releaseDates.length ? Math.min(...releaseDates) : null;
  const end = timestamp(major.eltsUntil);
  const maintained = timestamp(major.maintainedUntil);
  const picked = timestamp(releaseDateIso);
  if (start === null || end === null || maintained === null || picked === null) return null;

  const span = end - start;
  if (span <= 0) return null;

  const percent = (time: number): number => Math.min(100, Math.max(0, ((time - start) / span) * 100));

  return {
    freePercent: percent(maintained),
    todayPercent: percent(now.getTime()),
    releasePercent: percent(picked),
    monthsToMaintained: monthsBetween(now.getTime(), maintained),
    releaseAgeMonths: monthsBetween(picked, now.getTime()),
  };
}
