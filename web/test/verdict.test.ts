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
