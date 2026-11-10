<?php

namespace Shop\Http;

use Illuminate\Http\Request;
use Shop\Models\Category;
use Shop\Models\Product;
use Shop\Support\Money;

class ProductController
{
    public function show(Request $request): string
    {
        $product = new Product((int) $request->query('price'));
        $category = new Category('Cărți');

        return $category->name.': '.$product->price().' / '.Money::format(0);
    }
}
