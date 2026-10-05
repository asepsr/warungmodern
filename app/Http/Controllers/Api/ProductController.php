<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function menu(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with(['products' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:100']]);
        $term = Str::lower(trim($validated['q']));
        $products = $this->matchingProducts($term)->paginate(15);

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function match(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.query' => ['required', 'string', 'max:100'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $results = collect($validated['items'])->map(function (array $item): array {
            $term = Str::lower(trim($item['query']));

            return [
                'query' => $item['query'],
                'qty' => $item['qty'],
                'candidates' => $this->matchingProducts($term)->limit(5)->get(),
            ];
        });

        return response()->json(['success' => true, 'data' => $results]);
    }

    private function matchingProducts(string $term)
    {
        return Product::query()
            ->with('category:id,name')
            ->where('is_active', true)
            ->where(function ($query) use ($term) {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$term}%"])
                    ->orWhereJsonContains('aliases', $term);
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 WHEN LOWER(name) LIKE ? THEN 1 ELSE 2 END', [$term, "{$term}%"])
            ->orderBy('name');
    }
}
