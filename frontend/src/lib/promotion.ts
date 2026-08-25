const MONTHS = [
  'janvier',
  'février',
  'mars',
  'avril',
  'mai',
  'juin',
  'juillet',
  'août',
  'septembre',
  'octobre',
  'novembre',
  'décembre',
];

// Ex. « Bac + 2 "TP - ..." - mars 2026-2027 » → "mars 2026-2027"
// Ex. « Bac + 3 "..." - 2026-2027 (Groupe 1) » → "2026-2027" (le "(Groupe 1)" n'est pas capturé)
// Ex. « Salariés Ed Up » → null (aucune promotion identifiable)
const PROMOTION_PATTERN = new RegExp(`(?:(${MONTHS.join('|')})\\s+)?(\\d{4}-\\d{4})`, 'i');

export function parsePromotion(groupName: string): string | null {
  const match = groupName.match(PROMOTION_PATTERN);
  if (!match) {
    return null;
  }

  const month = match[1]?.toLowerCase();
  const yearRange = match[2];

  return month ? `${month} ${yearRange}` : yearRange;
}

export const NO_PROMOTION_LABEL = 'Autres groupes';

export function groupByPromotion<T extends { name: string }>(items: T[]): Map<string, T[]> {
  const buckets = new Map<string, T[]>();

  for (const item of items) {
    const promotion = parsePromotion(item.name) ?? NO_PROMOTION_LABEL;
    const bucket = buckets.get(promotion);
    if (bucket) {
      bucket.push(item);
    } else {
      buckets.set(promotion, [item]);
    }
  }

  return buckets;
}
