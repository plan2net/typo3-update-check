import type { Typo3Data } from './types';

export async function loadData(): Promise<Typo3Data> {
  const res = await fetch(`${import.meta.env.BASE_URL}data/typo3.json`, { cache: 'no-cache' });
  if (!res.ok) throw new Error(`Could not load data (${res.status})`);
  return (await res.json()) as Typo3Data;
}
