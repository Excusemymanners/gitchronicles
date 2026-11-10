import axios from 'axios';
import ProductCard from './components/product-card';
import { formatPrice } from '@/lib/format';

export async function showProduct(id: number): Promise<string> {
    const response = await axios.get(`/products/${id}`);

    return ProductCard({ price: formatPrice(response.data.price) });
}
