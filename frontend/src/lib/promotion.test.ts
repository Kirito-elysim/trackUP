import { describe, expect, it } from 'vitest';
import { groupByPromotion, NO_PROMOTION_LABEL, parsePromotion } from './promotion';

describe('parsePromotion', () => {
  it('extracts a month-prefixed promotion', () => {
    expect(parsePromotion('Bac + 2 "TP - Négociateur technico-commercial" - mars 2026-2027')).toBe(
      'mars 2026-2027',
    );
    expect(parsePromotion('Bac + 2 "TP - Négociateur technico-commercial" - octobre 2025-2026')).toBe(
      'octobre 2025-2026',
    );
  });

  it('extracts a bare year-range promotion and ignores a trailing group suffix', () => {
    expect(
      parsePromotion('Bac + 3 "Titre professionnel - Responsable d\'établissement marchand" - 2026-2027 (Groupe 1)'),
    ).toBe('2026-2027');
    expect(parsePromotion('BTS négociation et digitalisation de la relation client (1ère année) - 2025-2026')).toBe(
      '2025-2026',
    );
  });

  it('returns null when no year range is present', () => {
    expect(parsePromotion('Salariés Ed Up')).toBeNull();
  });
});

describe('groupByPromotion', () => {
  it('buckets items by parsed promotion, falling back to a catch-all bucket', () => {
    const groups = [
      { name: 'Bac + 2 "TP" - mars 2026-2027' },
      { name: 'Bac + 2 "TP" - octobre 2025-2026' },
      { name: 'Bac + 3 "TP" - 2026-2027 (Groupe 1)' },
      { name: 'Salariés Ed Up' },
    ];

    const buckets = groupByPromotion(groups);

    expect(buckets.size).toBe(4);
    expect(buckets.get('mars 2026-2027')).toHaveLength(1);
    expect(buckets.get(NO_PROMOTION_LABEL)).toHaveLength(1);
  });
});
