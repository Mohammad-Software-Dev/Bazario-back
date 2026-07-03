<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Service;
use App\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    use ApiResponseTrait;

    private const LATEST_LIMIT = 8;

    public function index(Request $request)
    {
        $latestLimit = max(1, min((int) $request->integer('latest_limit', self::LATEST_LIMIT), 24));

        return $this->successResponse([
            'products' => [
                'latest' => $this->latestProducts($latestLimit),
            ],
            'services' => [
                'latest' => $this->latestServices($latestLimit),
            ],
            'ads' => [
                'latest' => [],
            ],
        ], 'messages', 'home_retrieved_successfully');
    }

    private function latestProducts(int $limit)
    {
        return $this->productQuery()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function latestServices(int $limit)
    {
        return $this->serviceQuery()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function productQuery(): Builder
    {
        return Product::query()
            ->with([
                'images:id,product_id,image',
                'category:id,name',
                'seller.user:id,name,email,phone',
                'seller:id,user_id,store_name,store_owner_name,logo,address,description',
            ])
            ->select('id', 'name', 'description', 'price', 'category_id', 'seller_id', 'created_at');
    }

    private function serviceQuery(): Builder
    {
        return Service::query()
            ->with([
                'images:id,service_id,image',
                'category:id,name',
                'serviceProvider.user:id,name,email,phone',
                'serviceProvider:id,user_id,name,logo,address,description',
            ])
            ->select('id', 'title', 'description', 'price', 'category_id', 'provider_id', 'created_at');
    }
}
