import { describe, it, expect } from 'vitest';
import { computeVerdict } from '../src/verdict';
import type { Typo3Data } from '../src/types';

const NOW = new Date('2026-06-16T00:00:00Z');

const data: Typo3Data = {
  generatedAt: NOW.toISOString(),
  majors: {
    '12': {
      maintainedUntil: '2026-04-30T00:00:00+02:00',
      eltsUntil: '2030-04-30T00:00:00+02:00',
      latestFree: '12.4.45',
      latestElts: '12.4.47',
      releases: [
        { version: '12.4.10', date: null, type: 'regular', elts: false },
        { version: '12.4.45', date: null, type: 'regular', elts: false },
        { version: '12.4.46', date: null, type: 'security', elts: true },
        { version: '12.4.47', date: null, type: 'regular', elts: true },
      ],
    },
    '13': {
      maintainedUntil: '2027-12-31T00:00:00+01:00',
      eltsUntil: '2030-12-31T00:00:00+01:00',
      latestFree: '13.4.31',
      latestElts: '13.4.31',
      releases: [
        { version: '13.4.30', date: null, type: 'regular', elts: false },
        { version: '13.4.31', date: null, type: 'security', elts: false },
      ],
    },
  },
  advisories: [
    {
      id: 'SA-FREE-FIX',
      cve: 'CVE-1', package: 'typo3/cms-core', optional: false, severity: 'high',
      title: 'Fixed in a free release', affectedVersions: '>=12.0.0,<12.4.45', link: '#',
      affected: { '12': { from: '12.4.10', fixedIn: '12.4.45', fixedInElts: false } },
      explanation: null,
    },
    {
      id: 'SA-ELTS-FIX',
      cve: 'CVE-2', package: 'typo3/cms-core', optional: false, severity: 'critical',
      title: 'Fixed only in an ELTS release', affectedVersions: '>=12.0.0,<12.4.46', link: '#',
      affected: { '12': { from: '12.4.10', fixedIn: '12.4.46', fixedInElts: true } },
      explanation: null,
    },
  ],
};

describe('computeVerdict', () => {
  it('flags a missing FREE security fix as critical and recommends the free release', () => {
    const v = computeVerdict('12.4.10', false, data, NOW);
    expect(v.tier).toBe('critical-missing-fix');
    expect(v.recommendedVersion).toBe('12.4.45');
    expect(v.affecting.length).toBe(2); // both advisories still affect 12.4.10
  });

  it('warns a free user that an ELTS-only core fix is NOT resolved by the free update', () => {
    const v = computeVerdict('12.4.10', false, data, NOW);
    expect(v.tier).toBe('critical-missing-fix');
    // SA-ELTS-FIX is fixed only in ELTS 12.4.46 — updating to the free 12.4.45 leaves it open.
    expect(v.concerns.some((c) => /ELTS/i.test(c))).toBe(true);
  });

  it('does not raise the ELTS-gap warning for an ELTS subscriber (the gated fix is reachable)', () => {
    const v = computeVerdict('12.4.10', true, data, NOW);
    expect(v.tier).toBe('critical-missing-fix');
    expect(v.concerns.some((c) => /fixed only in ELTS/i.test(c))).toBe(false);
  });

  it('on the latest free release, surfaces the ELTS-only gap (free user)', () => {
    const v = computeVerdict('12.4.45', false, data, NOW);
    expect(v.tier).toBe('critical-elts-only');
    expect(v.supportPhase).toBe('elts-only');
    expect(v.recommendedVersion).toBeNull();
    // SA-FREE-FIX is patched at 12.4.45; SA-ELTS-FIX (fix 12.4.46) still affects
    expect(v.affecting.length).toBe(1);
  });

  it('with ELTS, the same case recommends the latest ELTS release', () => {
    const v = computeVerdict('12.4.45', true, data, NOW);
    expect(v.tier).toBe('critical-missing-fix');
    expect(v.recommendedVersion).toBe('12.4.47');
  });

  it('reports all-good on the latest free release of a supported major', () => {
    const v = computeVerdict('13.4.31', false, data, NOW);
    expect(v.tier).toBe('all-good');
    expect(v.affecting.length).toBe(0);
  });

  it('reports behind-maintenance when secure but not on the latest free release', () => {
    const v = computeVerdict('13.4.30', false, data, NOW);
    expect(v.tier).toBe('behind-maintenance');
    expect(v.recommendedVersion).toBe('13.4.31');
  });

  it('reports end-of-life once past eltsUntil', () => {
    const future = new Date('2031-01-01T00:00:00Z');
    const v = computeVerdict('12.4.45', false, data, future);
    expect(v.tier).toBe('critical-eol');
    expect(v.supportPhase).toBe('eol');
  });

  it('reports end-of-life even when an intra-line fix is still reachable', () => {
    const future = new Date('2031-01-01T00:00:00Z'); // past major 12 eltsUntil (2030-04-30)
    const v = computeVerdict('12.4.10', false, data, future); // SA-FREE-FIX reaches a free patch at 12.4.45…
    expect(v.tier).toBe('critical-eol'); // …but EOL dominates: the whole line is unsupported
  });

  it('returns unknown-version for an unparseable input', () => {
    const v = computeVerdict('12.4', false, data, NOW);
    expect(v.tier).toBe('unknown-version');
  });

  it('canonicalises a leading-v input and treats it like the bare version', () => {
    expect(computeVerdict('v12.4.10', false, data, NOW).tier)
      .toBe(computeVerdict('12.4.10', false, data, NOW).tier);
  });

  it('rejects a well-formed but non-existent version instead of saying all-good', () => {
    const v = computeVerdict('13.99.99', false, data, NOW); // parses, major 13 exists, but not a real release
    expect(v.tier).toBe('unknown-version');
  });
});

describe('computeVerdict — core/optional split, unfixed, concerns', () => {
  const active: Typo3Data = {
    generatedAt: NOW.toISOString(),
    majors: {
      '13': {
        maintainedUntil: '2027-12-31T00:00:00+01:00',
        eltsUntil: '2030-12-31T00:00:00+01:00',
        latestFree: '13.4.31',
        latestElts: '13.4.31',
        releases: [
          { version: '13.4.30', date: null, type: 'regular', elts: false },
          { version: '13.4.31', date: null, type: 'security', elts: false },
        ],
      },
    },
    advisories: [
      {
        id: 'SA-CORE', cve: 'CVE-A', package: 'typo3/cms-core', optional: false, severity: 'high',
        title: 'Core, free fix', affectedVersions: '>=13.0.0,<13.4.31', link: '#',
        affected: { '13': { from: '13.4.30', fixedIn: '13.4.31', fixedInElts: false } },
        explanation: null,
      },
      {
        id: 'SA-OPT', cve: 'CVE-B', package: 'typo3/cms-form', optional: true, severity: 'medium',
        title: 'Optional extension', affectedVersions: '>=13.0.0,<13.4.31', link: '#',
        affected: { '13': { from: '13.4.30', fixedIn: '13.4.31', fixedInElts: false } },
        explanation: null,
      },
      {
        id: 'SA-UNFIXED', cve: 'CVE-C', package: 'typo3/cms-core', optional: false, severity: 'high',
        title: 'Core, no fix yet', affectedVersions: '>=13.0.0', link: '#',
        affected: { '13': { from: '13.4.30', fixedIn: null, fixedInElts: false } },
        explanation: null,
      },
    ],
  };

  it('a core free fix is the headline; the optional advisory is not counted but is a concern', () => {
    const v = computeVerdict('13.4.30', false, active, NOW);
    expect(v.tier).toBe('critical-missing-fix');
    expect(v.affecting.filter((a) => !a.optional).length).toBe(2); // SA-CORE + SA-UNFIXED
    expect(v.affecting.filter((a) => a.optional).length).toBe(1);  // SA-OPT, not in the count
    expect(v.concerns.some((c) => /may also apply/i.test(c))).toBe(true);
    expect(v.concerns.some((c) => /no released fix/i.test(c))).toBe(true);
  });

  it('falls to critical-unfixed when the only remaining core issue has no fix', () => {
    const v = computeVerdict('13.4.31', false, active, NOW); // SA-CORE & SA-OPT patched at 13.4.31
    expect(v.tier).toBe('critical-unfixed');
    expect(v.affecting.length).toBe(1); // only SA-UNFIXED still applies
  });

  it('reports review-optional when only an optional advisory applies', () => {
    const optionalOnly: Typo3Data = { ...active, advisories: [active.advisories[1]!] };
    const v = computeVerdict('13.4.30', false, optionalOnly, NOW);
    expect(v.tier).toBe('review-optional');
    expect(v.affecting.every((a) => a.optional)).toBe(true);
  });

  it('renders the same tier with German prose when lang=de', () => {
    const en = computeVerdict('13.4.30', false, active, NOW, 'en');
    const de = computeVerdict('13.4.30', false, active, NOW, 'de');
    expect(de.tier).toBe(en.tier);              // logic is language-neutral
    expect(de.headline).toMatch(/aktualisieren/i); // but prose is German
    expect(de.headline).not.toBe(en.headline);
  });
});

describe('computeVerdict — data freshness (fail closed on a stalled pipeline)', () => {
  const later = new Date('2027-06-01T00:00:00Z');
  const staleData = { ...data, checkedAt: '2026-01-01T00:00:00Z' }; // last verified ~17 months before `later`

  it('downgrades a reassuring verdict to stale-data when checkedAt is old', () => {
    const v = computeVerdict('13.4.31', false, staleData, later); // would be all-good
    expect(v.tier).toBe('stale-data');
    expect(v.recommendedVersion).toBeNull();
  });

  it('trusts a recent checkedAt', () => {
    const fresh = { ...data, checkedAt: '2027-05-30T00:00:00Z' };
    const v = computeVerdict('13.4.31', false, fresh, later);
    expect(v.tier).toBe('all-good');
  });

  it('does not treat an old generatedAt as stale when there is no checkedAt heartbeat', () => {
    // dev/preview/self-host without the CI stamp: generatedAt only moves on a data change, so it is
    // not a freshness signal and must not raise a false "Unconfirmed".
    const v = computeVerdict('13.4.31', false, data, later); // generatedAt 2026-06-16, no checkedAt
    expect(v.tier).toBe('all-good');
  });

  it('clears the disclaimed data’s concerns when downgrading to stale-data', () => {
    const withNewerMajor: Typo3Data = {
      ...staleData,
      majors: {
        ...staleData.majors,
        '14': {
          maintainedUntil: '2029-12-31T00:00:00+01:00', eltsUntil: '2032-12-31T00:00:00+01:00',
          latestFree: '14.0.0', latestElts: '14.0.0',
          releases: [{ version: '14.0.0', date: null, type: 'regular', elts: false }],
        },
      },
    };
    // 13.4.31 would be all-good with a "TYPO3 14 available" concern; the stale banner must not carry it.
    const v = computeVerdict('13.4.31', false, withNewerMajor, later);
    expect(v.tier).toBe('stale-data');
    expect(v.concerns).toHaveLength(0);
  });

  it('hides the disclaimed advisory lists when downgrading to stale-data', () => {
    const optionalOnly: Typo3Data = {
      ...staleData,
      advisories: [
        {
          id: 'SA-OPT', cve: 'CVE-B', package: 'typo3/cms-form', optional: true, severity: 'medium',
          title: 'Optional extension', affectedVersions: '>=13.0.0,<13.4.31', link: '#',
          affected: { '13': { from: '13.4.30', fixedIn: '13.4.31', fixedInElts: false } },
          explanation: null,
        },
      ],
    };
    // 13.4.30 would be review-optional listing SA-OPT; under "can't confirm" nothing may be listed.
    const v = computeVerdict('13.4.30', false, optionalOnly, later);
    expect(v.tier).toBe('stale-data');
    expect(v.affecting).toHaveLength(0);
  });

  it('keeps a critical verdict under staleness but drops its (possibly obsolete) recommendation', () => {
    const v = computeVerdict('12.4.10', false, staleData, later); // stale, but live criticals affect 12.4.10
    expect(v.tier).toBe('critical-missing-fix');           // criticality stands (over-reporting is safe)
    expect(v.recommendedVersion).toBeNull();               // stale dataset's "latest" may be obsolete
    expect(v.headline).not.toContain('12.4.45');           // headline must not name the stale target
    expect(v.detail).not.toContain('12.4.45');
    expect(v.concerns.some((c) => /out of date|current release/i.test(c))).toBe(true);
  });

  it('a fresh critical verdict still names the target in the headline', () => {
    const v = computeVerdict('12.4.10', false, data, NOW); // no checkedAt → not stale
    expect(v.tier).toBe('critical-missing-fix');
    expect(v.recommendedVersion).toBe('12.4.45');
    expect(v.headline).toContain('12.4.45');
  });

  it('treats an empty checkedAt as malformed (stale), not as a missing heartbeat', () => {
    const emptyStamp = { ...data, checkedAt: '' };
    const v = computeVerdict('13.4.31', false, emptyStamp, later); // would be all-good
    expect(v.tier).toBe('stale-data');
  });

  it('cautions that an unknown version may be newer than stale release data', () => {
    // Pipeline stalled; a genuinely new release is missing from the list — the data is the
    // problem, not the user's input, so the unknown-version verdict must say so.
    const v = computeVerdict('13.4.35', false, staleData, later);
    expect(v.tier).toBe('unknown-version');
    expect(v.concerns.some((c) => /newer than our data/i.test(c))).toBe(true);
  });

  it('cautions that an unknown major may be newer than stale release data', () => {
    const v = computeVerdict('14.0.0', false, staleData, later);
    expect(v.tier).toBe('unknown-version');
    expect(v.concerns.some((c) => /newer than our data/i.test(c))).toBe(true);
  });

  it('does not caution about unknown versions when the data is fresh', () => {
    const v = computeVerdict('13.4.35', false, data, NOW); // no heartbeat → no staleness assertion
    expect(v.tier).toBe('unknown-version');
    expect(v.concerns).toHaveLength(0);
  });
});
