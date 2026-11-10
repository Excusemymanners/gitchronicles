export function formatPrice(cents: number): string {
    return `${roundPrice(cents / 100)} lei`;
}

export const roundPrice = (value: number): number => Math.round(value * 100) / 100;
