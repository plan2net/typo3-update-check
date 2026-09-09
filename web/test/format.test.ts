import { describe, it, expect } from 'vitest';
import { splitAdvisoryTitle, severityCounts, fixSplit, supportTimeline } from '../src/format';
import type { AffectingAdvisory, AdvisoryInfo, MajorInfo } from '../src/types';

function advisory(overrides: Partial<AdvisoryInfo> = {}): AdvisoryInfo {
  return {
    id: 'PKSA-x',
    cve: null,
    package: 'typo3/cms-core',
    optional: false,
    severity: 'high',
    title: 'Something',
    affectedVersions: '',
    link: 'https://example.test',
    affected: {},
    explanation: null,
    ...overrides,
  };
}

function affecting(fixVersion: string | null, fixIsFree: boolean): AffectingAdvisory {
  return { advisory: advisory(), fixVersion, fixIsFree, optional: false };
}

function major(overrides: Partial<MajorInfo> = {}): MajorInfo {
  return {
    maintainedUntil: '2026-04-30T00:00:00+02:00',
    eltsUntil: '2030-04-30T00:00:00+02:00',
    latestFree: '12.4.45',
    latestElts: '12.4.48',
    releases: [
      { version: '12.4.48', date: '2026-07-14T12:30:55+02:00', type: 'regular', elts: true },
      { version: '12.0.0', date: '2022-10-04T00:00:00+02:00', type: 'regular', elts: false },
    ],
    ...overrides,
  };
}

describe('splitAdvisoryTitle', () => {
  it('lifts a leading TYPO3-CORE-SA identifier out of the title', () => {
    expect(splitAdvisoryTitle('TYPO3-CORE-SA-2026-019: Broken Access Control in Form Framework')).toEqual({
      advisoryId: 'TYPO3-CORE-SA-2026-019',
      title: 'Broken Access Control in Form Framework',
    });
  });

  it('leaves a title without an identifier untouched', () => {
    const plain = 'TYPO3 Allows Privilege Escalation to System Maintainer';
    expect(splitAdvisoryTitle(plain)).toEqual({ advisoryId: null, title: plain });
  });

  it('does not treat a bare TYPO3 mention as an identifier', () => {
    const plain = 'TYPO3 CMS: Broken Access Control in Clipboard';
    expect(splitAdvisoryTitle(plain)).toEqual({ advisoryId: null, title: plain });
  });

  it('tolerates surrounding whitespace', () => {
    expect(splitAdvisoryTitle('  TYPO3-CORE-SA-2026-008:   Broken Access Control  ')).toEqual({
      advisoryId: 'TYPO3-CORE-SA-2026-008',
      title: 'Broken Access Control',
    });
  });
});

describe('severityCounts', () => {
  it('counts by severity, most severe first', () => {
    expect(severityCounts(['medium', 'high', 'low', 'high', 'medium'])).toEqual([
      { severity: 'high', count: 2 },
      { severity: 'medium', count: 2 },
      { severity: 'low', count: 1 },
    ]);
  });

  it('sorts unrated severities last', () => {
    expect(severityCounts(['unknown', 'critical'])).toEqual([
      { severity: 'critical', count: 1 },
      { severity: 'unknown', count: 1 },
    ]);
  });

  it('returns nothing for an empty list', () => {
    expect(severityCounts([])).toEqual([]);
  });
});

describe('fixSplit', () => {
  it('separates advisories a free update clears from ELTS-only ones', () => {
    const split = fixSplit([
      affecting('12.4.41', true),
      affecting('12.4.42', true),
      affecting('12.4.46', false),
    ]);
    expect(split).toEqual({ free: 2, eltsOnly: 1, unfixed: 0 });
  });

  it('counts an advisory with no released fix separately', () => {
    expect(fixSplit([affecting(null, false), affecting('12.4.41', true)])).toEqual({
      free: 1,
      eltsOnly: 0,
      unfixed: 1,
    });
  });

  it('is all zeroes for an empty list', () => {
    expect(fixSplit([])).toEqual({ free: 0, eltsOnly: 0, unfixed: 0 });
  });
});

describe('supportTimeline', () => {
  const now = new Date('2026-09-03T00:00:00Z');

  it('places the maintenance boundary, today and the release on the span', () => {
    const timeline = supportTimeline(major(), '2025-05-14T00:00:00+02:00', now);
    expect(timeline).not.toBeNull();
    // Span 2022-10-04 → 2030-04-30, boundary 2026-04-30 (~46% in), today Sep 2026,
    // picked release May 2025.
    expect(timeline!.freePercent).toBeGreaterThan(44);
    expect(timeline!.freePercent).toBeLessThan(48);
    expect(timeline!.todayPercent).toBeGreaterThan(timeline!.freePercent);
    expect(timeline!.releasePercent).toBeLessThan(timeline!.freePercent);
  });

  it('reports a negative month count once free support has ended', () => {
    const timeline = supportTimeline(major(), '2025-05-14T00:00:00+02:00', now);
    expect(timeline!.monthsToMaintained).toBeLessThan(0);
    expect(timeline!.releaseAgeMonths).toBeGreaterThan(14);
  });

  it('reports months remaining while free support still runs', () => {
    const stillFree = major({ maintainedUntil: '2027-12-31T00:00:00+01:00', eltsUntil: '2030-12-31T00:00:00+01:00' });
    const timeline = supportTimeline(stillFree, '2025-11-11T00:00:00+01:00', now);
    expect(timeline!.monthsToMaintained).toBeGreaterThan(0);
  });

  it('clamps a release older than the recorded span to zero', () => {
    const timeline = supportTimeline(major(), '2019-01-01T00:00:00+01:00', now);
    expect(timeline!.releasePercent).toBe(0);
  });

  it('returns null when the major has no release dates to anchor the span', () => {
    const undated = major({ releases: [{ version: '12.4.48', date: null, type: 'regular', elts: true }] });
    expect(supportTimeline(undated, '2025-05-14T00:00:00+02:00', now)).toBeNull();
  });

  it('returns null when the span is not a real interval', () => {
    const broken = major({ eltsUntil: '2020-01-01T00:00:00+01:00' });
    expect(supportTimeline(broken, '2025-05-14T00:00:00+02:00', now)).toBeNull();
  });

  it('returns null when the picked release has no date', () => {
    expect(supportTimeline(major(), null, now)).toBeNull();
  });
});
