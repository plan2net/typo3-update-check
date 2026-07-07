import type { Lang } from './types';

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];
const LOCALE: Record<Lang, string> = { en: 'en-GB', de: 'de-DE' };

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
