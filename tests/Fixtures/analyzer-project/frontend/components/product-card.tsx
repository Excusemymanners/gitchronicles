import { formatPrice } from '../lib/format';

interface Props {
    price: string;
}

export default function ProductCard({ price }: Props) {
    return `<div class="product">${price || formatPrice(0)}</div>`;
}
