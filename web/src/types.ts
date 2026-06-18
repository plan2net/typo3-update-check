export interface ReleaseInfo {
  version: string;
  date: string | null;
  type: string; // "regular" | "security" | ...
  elts: boolean;
}

export interface MajorInfo {
  maintainedUntil: string; // ISO 8601
  eltsUntil: string;       // ISO 8601
  latestFree: string;      // highest release with elts === false
  latestElts: string;      // highest release overall
  releases: ReleaseInfo[];
}

export interface AffectedEntry {
  from: string;             // first affected real release in this major
  fixedIn: string | null;   // first patched real release, or null if no fix yet
  fixedInElts: boolean;     // whether that fix is an ELTS-only release
}

export type Lang = 'en' | 'de';
export const LANGS: Lang[] = ['en', 'de'];

export interface Explanation {
  plainImpact: string;
  urgency: string;
}

export interface AdvisoryInfo {
  id: string;
  cve: string | null;
  package: string;
  optional: boolean; // false = always-present core package; true = "may apply if installed" (build-stamped)
  severity: string; // critical | high | medium | low | unknown
  title: string;
  affectedVersions: string;
  link: string;
  affected: Record<string, AffectedEntry>;     // keyed by integer major, e.g. "12"
  explanation: Partial<Record<Lang, Explanation>> | null; // per language: { en, de }
}

export interface Typo3Data {
  generatedAt: string;
  majors: Record<string, MajorInfo>; // keyed by integer major, e.g. "12"
  advisories: AdvisoryInfo[];
}

export type SupportPhase = 'active' | 'elts-only' | 'eol' | 'unknown';

export type Tier =
  | 'critical-missing-fix'
  | 'critical-unfixed'
  | 'critical-elts-only'
  | 'critical-eol'
  | 'soon-support-ending'
  | 'review-optional'
  | 'behind-maintenance'
  | 'all-good'
  | 'unknown-version';

export interface AffectingAdvisory {
  advisory: AdvisoryInfo;
  fixVersion: string | null; // null = no released fix in this line yet
  fixIsFree: boolean;
  optional: boolean; // mirrors advisory.optional, for convenient grouping
}

export interface Verdict {
  tier: Tier;
  supportPhase: SupportPhase;
  recommendedVersion: string | null;
  headline: string;
  detail: string;
  affecting: AffectingAdvisory[]; // both core and optional; group on `optional` in the UI
  concerns: string[];             // secondary plain-language notes the headline doesn't cover
}
