export type SortDirection = 'asc' | 'desc';

export function compareValues(
  left: string | number | null,
  right: string | number | null,
  direction: SortDirection,
): number {
  const leftValue = left ?? '';
  const rightValue = right ?? '';
  const multiplier = direction === 'asc' ? 1 : -1;

  if (typeof leftValue === 'number' && typeof rightValue === 'number') {
    return (leftValue - rightValue) * multiplier;
  }

  return String(leftValue).localeCompare(String(rightValue), 'fr', { numeric: true }) * multiplier;
}
