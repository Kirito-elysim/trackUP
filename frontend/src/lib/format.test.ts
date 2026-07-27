import { describe, expect, it } from 'vitest';
import { clampPercentage, formatDuration, formatPercentage, minutesToHours } from './format';

describe('formatDuration', () => {
  it('formats minutes under an hour', () => {
    expect(formatDuration(45)).toBe('45 min');
  });

  it('formats hours with padded remaining minutes', () => {
    expect(formatDuration(125)).toBe('2 h 05');
  });

  it('formats an exact hour with no remainder', () => {
    expect(formatDuration(120)).toBe('2 h 00');
  });

  it('treats null/undefined as zero', () => {
    expect(formatDuration(null)).toBe('0 min');
    expect(formatDuration(undefined)).toBe('0 min');
  });

  it('clamps negative values to zero', () => {
    expect(formatDuration(-30)).toBe('0 min');
  });

  it('floors fractional minutes', () => {
    expect(formatDuration(45.9)).toBe('45 min');
  });
});

describe('formatPercentage', () => {
  it('rounds to the nearest integer', () => {
    expect(formatPercentage(45.4)).toBe('45 %');
    expect(formatPercentage(45.5)).toBe('46 %');
  });

  it('treats null/undefined as zero', () => {
    expect(formatPercentage(null)).toBe('0 %');
    expect(formatPercentage(undefined)).toBe('0 %');
  });
});

describe('clampPercentage', () => {
  it('passes nominal values through unchanged', () => {
    expect(clampPercentage(42)).toBe(42);
  });

  it('clamps values above 100', () => {
    expect(clampPercentage(150)).toBe(100);
  });

  it('clamps negative values to zero', () => {
    expect(clampPercentage(-10)).toBe(0);
  });

  it('treats null/undefined as zero', () => {
    expect(clampPercentage(null)).toBe(0);
    expect(clampPercentage(undefined)).toBe(0);
  });
});

describe('minutesToHours', () => {
  it('converts nominal values', () => {
    expect(minutesToHours(60)).toBe(1);
    expect(minutesToHours(90)).toBe(1.5);
  });

  it('treats null/undefined as zero', () => {
    expect(minutesToHours(null)).toBe(0);
    expect(minutesToHours(undefined)).toBe(0);
  });

  it('handles zero', () => {
    expect(minutesToHours(0)).toBe(0);
  });
});
