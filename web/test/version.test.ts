import { describe, it, expect } from 'vitest';
import { parseVersion, compareVersions, majorKey } from '../src/version';

describe('parseVersion', () => {
  it('accepts exact x.y.z and strips a leading v / whitespace', () => {
    expect(parseVersion('12.4.10')).toEqual([12, 4, 10]);
    expect(parseVersion('  v12.4.10 ')).toEqual([12, 4, 10]);
  });
  it('rejects major.minor and garbage', () => {
    expect(parseVersion('12.4')).toBeNull();
    expect(parseVersion('latest')).toBeNull();
  });
});

describe('compareVersions', () => {
  it('orders by numeric segments', () => {
    expect(compareVersions('12.4.9', '12.4.10')).toBe(-1);
    expect(compareVersions('12.4.46', '12.4.46')).toBe(0);
    expect(compareVersions('13.0.0', '12.9.9')).toBe(1);
  });
});

describe('majorKey', () => {
  it('returns the integer major as a string', () => {
    expect(majorKey('12.4.10')).toBe('12');
  });
});
