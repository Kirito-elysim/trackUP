import { describe, expect, it } from 'vitest';
import { compareValues } from './sort';

describe('compareValues', () => {
  it('sorts numbers ascending', () => {
    expect(compareValues(1, 2, 'asc')).toBeLessThan(0);
    expect(compareValues(2, 1, 'asc')).toBeGreaterThan(0);
    expect(compareValues(2, 2, 'asc')).toBe(0);
  });

  it('sorts numbers descending', () => {
    expect(compareValues(1, 2, 'desc')).toBeGreaterThan(0);
    expect(compareValues(2, 1, 'desc')).toBeLessThan(0);
  });

  it('sorts strings using French locale numeric comparison', () => {
    expect(compareValues('a', 'b', 'asc')).toBeLessThan(0);
    expect(compareValues('item2', 'item10', 'asc')).toBeLessThan(0); // numeric-aware
  });

  it('treats null as an empty value', () => {
    expect(compareValues(null, 'a', 'asc')).toBeLessThan(0);
    expect(compareValues('a', null, 'asc')).toBeGreaterThan(0);
    expect(compareValues(null, null, 'asc')).toBe(0);
  });
});
