<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ads\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use ApiResponseTrait;

    protected function productRelations(): array
    {
        return [
            'images:id,product_id,image',
            'category:id,name',
            'seller.user:id,name,email,phone',
            'seller:id,user_id,store_name,store_owner_name,logo,address,description',
        ];
    }

    protected function baseProductSelect(): array
    {
        return ['id', 'name', 'description', 'price', 'category_id', 'seller_id', 'created_at'];
    }

    protected function resolveAuthenticatedSeller(): ?Seller
    {
        $user = auth()->guard()->user();

        return Seller::where('user_id', $user->id)->first();
    }

    protected function ensureProductOwnership(Product $product, Seller $seller): void
    {
        abort_unless($product->seller_id === $seller->id, 403);
    }

    protected function syncImages(Product $product, Seller $seller, ProductRequest $request): void
    {
        if (!$request->hasFile('images')) {
            return;
        }

        foreach ($product->images as $image) {
            $path = preg_replace('#^storage/#', '', $image->image ?? '');

            if (!empty($path)) {
                Storage::disk('public')->delete($path);
            }

            $image->delete();
        }

        foreach ($request->file('images') as $image) {
            $product->images()->create([
                'image' => 'storage/' . $image->store('products/' . $seller->id, 'public'),
            ]);
        }
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));

        $query = Product::with($this->productRelations())
            ->select($this->baseProductSelect());

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($products, 'messages', 'products_retrieved_successfully');
    }

    public function show(Product $product)
    {
        $product->load($this->productRelations());

        return $this->successResponse($product, 'messages', 'products_retrieved_successfully');
    }

    public function myProducts()
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));
        $seller = $this->resolveAuthenticatedSeller();

        if (!$seller) {
            return $this->errorResponse('seller_not_found', 'messages', 404);
        }

        $query = Product::where('seller_id', $seller->id)
            ->with($this->productRelations())
            ->select($this->baseProductSelect());

        if (request()->has('category_id')) {
            $query->where('category_id', request('category_id'));
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($products, 'messages', 'products_retrieved_successfully');
    }

    public function productsBySeller($id)
    {
        $perPage = max(1, min((int) request('per_page', 20), 50));

        $seller = Seller::query()
            ->with(['user:id,name,email,phone'])
            ->select('id', 'user_id', 'store_name', 'store_owner_name', 'logo', 'address', 'description')
            ->findOrFail($id);

        $products = Product::where('seller_id', $seller->id)
            ->with($this->productRelations())
            ->select($this->baseProductSelect())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->successResponse(
            [
                'seller' => $seller,
                'products' => $products,
            ],
            'messages',
            'products_retrieved_successfully'
        );
    }

    public function store(ProductRequest $request)
    {
        $data = $request->except('images');
        $seller = $this->resolveAuthenticatedSeller();

        if (!$seller) {
            return $this->errorResponse('seller_not_found', 'messages', 404);
        }

        $data['seller_id'] = $seller->id;
        $product = Product::create($data);

        $this->syncImages($product, $seller, $request);

        return $this->successResponse(
            $product->fresh()->load($this->productRelations()),
            'products',
            'product_created_successfully'
        );
    }

    public function update(ProductRequest $request, Product $product)
    {
        $seller = $this->resolveAuthenticatedSeller();

        if (!$seller) {
            return $this->errorResponse('seller_not_found', 'messages', 404);
        }

        $this->ensureProductOwnership($product, $seller);

        $product->update($request->except('images'));
        $this->syncImages($product, $seller, $request);

        return $this->successResponse(
            $product->fresh()->load($this->productRelations()),
            'products',
            'product_updated_successfully'
        );
    }

    public function destroy(Product $product)
    {
        $seller = $this->resolveAuthenticatedSeller();

        if (!$seller) {
            return $this->errorResponse('seller_not_found', 'messages', 404);
        }

        $this->ensureProductOwnership($product, $seller);

        foreach ($product->images as $image) {
            $path = preg_replace('#^storage/#', '', $image->image ?? '');

            if (!empty($path)) {
                Storage::disk('public')->delete($path);
            }

            $image->delete();
        }

        $product->delete();

        return $this->successResponse([], 'products', 'product_deleted_successfully');
    }

    public function productsByCategory($categoryId)
    {
        $categoryIds = Category::where('parent_id', $categoryId)
            ->pluck('id')->toArray();
        $categoryIds[] = $categoryId;

        $products = Product::whereIn('category_id', $categoryIds)
            ->with($this->productRelations())
            ->select($this->baseProductSelect())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->successResponse($products, 'messages', 'products_retrieved_successfully');
    }
}
